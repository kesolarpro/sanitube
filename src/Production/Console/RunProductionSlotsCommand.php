<?php

declare(strict_types=1);

namespace SaniTube\Production\Console;

use Illuminate\Console\Command;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\ProduceForProductionSlot;
use SaniTube\Production\Services\ReclaimProductionSlots;
use SaniTube\Production\Services\SettleProductionSlot;
use SaniTube\Production\Services\WorkProductionSlot;

/**
 * Acting on the occasions a plan opened.
 *
 * **This is the command that spends money**, and the only one in the platform
 * that does so with nobody present. `sanitube:production:open-slots` writes
 * rows; this one turns them into requests at a supplier. The two are separate
 * commands for exactly that reason: an operator can schedule the harmless one
 * and run this one by hand until they trust it.
 *
 * Neither is scheduled by default — see `ProductionServiceProvider` for why the
 * module registers commands and schedules nothing. That argument is stronger
 * here than anywhere: a scheduled entry that quietly begins paying a supplier
 * is not something a platform should install on somebody's behalf.
 *
 * **Reclaiming runs first, then settling, then producing**, and the order is
 * the recovery story read forwards. An occasion abandoned by a dead worker goes
 * back to PENDING in the reclaim pass and is picked up by the produce pass of
 * the *same* run — so a crash costs one cycle rather than for ever. Settling
 * before producing matters for the same reason.
 *
 * **Settling runs before producing**, and the order matters. A slot whose
 * generation failed is re-offered to another supplier during the settle pass,
 * so doing it first means a bad afternoon at one provider is recovered in the
 * same run rather than the next one. It also means the inventory that decides
 * whether more music is wanted counts a run that has already been reconciled.
 *
 * The global stop is asked first, as every unattended worker does. An operator
 * who paused the platform meant it, and this is the one place where ignoring
 * that would cost them money rather than time.
 */
final class RunProductionSlotsCommand extends Command
{
    protected $signature = 'sanitube:production:run
        {--limit=25 : how many due slots to take in this run}
        {--settle-only : recover and reconcile occasions already in flight, and start nothing new}';

    protected $description = 'Recover abandoned production slots, settle the ones in flight, then start work on the ones that are due.';

    public function handle(
        ReclaimProductionSlots $reaper,
        SettleProductionSlot $settler,
        ProduceForProductionSlot $producer,
        BackgroundWork $work,
    ): int {
        if ($work->isPaused()) {
            $this->error('Refused: BACKGROUND_WORK_PAUSED');
            $this->line('Nothing was recovered, nothing was settled and nothing was requested. Resume the platform to let plans continue.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        // First, because an occasion abandoned by a dead worker is one this
        // run can then do properly. Its own bound, from configuration: a
        // recovery run after a long outage must not starve the work it exists
        // to unblock.
        $recovered = $reaper->handle();

        $this->info(sprintf('Recovered %d abandoned occasion(s).', $recovered['reclaimed']));

        if ($recovered['unknown'] > 0) {
            // Said loudly, and never folded in with failures. These reached a
            // supplier and lost the answer, so somebody may have been charged
            // for a track this platform has no record of.
            $this->warn(sprintf(
                '%d occasion(s) reached a supplier and lost the answer. They are recorded as UNKNOWN and will not be retried: check the supplier\'s own record before deciding.',
                $recovered['unknown'],
            ));
        }

        $settled = 0;

        foreach ($this->inFlight($limit) as $slot) {
            if ($settler->handle($slot) instanceof ProductionSlot) {
                $settled++;
            }
        }

        $this->info(sprintf('Settled %d occasion(s) that were already in flight.', $settled));

        if ((bool) $this->option('settle-only')) {
            $this->line('Nothing new was started: --settle-only. Recovery still ran, because taking back an occasion nobody is working on starts nothing.');

            return self::SUCCESS;
        }

        $started = 0;

        foreach ($this->due($limit) as $slot) {
            // The worker names itself, so the question after an incident --
            // which process took this -- has an answer. The run's own name and
            // the host, because two servers sharing a database is ordinary.
            if ($producer->handle($slot, $this->worker()) !== null) {
                $started++;
            }
        }

        $this->info(sprintf('Requested %d generation(s).', $started));

        // Said plainly. "Requested 3 generations" reads as "made three tracks"
        // to anybody who has not read ProduceForProductionSlot, and the
        // difference is minutes of provider time and a review queue.
        $this->line('A requested generation is a job queued at a supplier. Nothing has entered the catalogue: finished audio becomes a candidate somebody still has to listen to.');

        return self::SUCCESS;
    }

    /**
     * Occasions that have started and not finished.
     *
     * Oldest first, and by id after that: ADR-0017's tiebreak rule. Two slots
     * claimed in the same second must come back in the same order on every run
     * or a limit silently starves one of them.
     *
     * @return iterable<ProductionSlot>
     */
    private function inFlight(int $limit): iterable
    {
        return ProductionSlot::query()
            ->where('status', ProductionSlotStatus::Claimed->value)
            ->whereNotNull('music_generation_id')
            ->with(['generation', 'plan'])
            ->orderBy('claimed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Occasions whose day has come and which nobody has taken.
     *
     * The claim is what actually decides who gets one -- this list is only a
     * suggestion, and {@see WorkProductionSlot}
     * re-checks everything, because between choosing and claiming there is a
     * gap.
     *
     * @return iterable<ProductionSlot>
     */
    private function due(int $limit): iterable
    {
        return ProductionSlot::query()
            ->where('status', ProductionSlotStatus::Pending->value)
            ->whereDate('scheduled_for', '<=', now()->startOfDay())
            ->with('plan')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function worker(): string
    {
        return sprintf('%s@%s', $this->getName() ?? 'production:run', gethostname() ?: 'unknown');
    }
}
