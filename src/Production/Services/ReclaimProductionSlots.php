<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use Illuminate\Support\Carbon;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;

/**
 * Taking back occasions whose worker never came home.
 *
 * PROD-002 made claiming atomic so two workers cannot both take one occasion.
 * Nothing made a claim **expire**. A process killed between claiming and acting
 * left the occasion CLAIMED for ever, invisible to everything: the scheduler
 * will not re-open a date that already has a slot, and no worker will take one
 * that is not PENDING. PROD-004 named the gap in a docblock and left it —
 * *"PROD-005's reaper is what should decide when a claim is stale enough to
 * take back"* — and this is that.
 *
 * **The reaper's whole job is knowing which claims are safe to take, and its
 * answer is narrow on purpose.** An occasion may have reached a supplier before
 * its worker died, and asking a second supplier for an occasion that has
 * already been paid for is precisely the failure the slot lifecycle exists to
 * prevent. So the question is not "is this claim old" but **"was anybody
 * asked"**, and three cases fall out of it:
 *
 *   1. **Nobody was asked.** No generation, no attempted supplier. Nothing was
 *      spent, so the occasion goes back to PENDING and the next run treats it
 *      as it would have before the crash. This is the ordinary case.
 *   2. **A generation exists.** Not the reaper's business at any age:
 *      {@see SettleProductionSlot} owns it and closes it when the supplier
 *      answers. A generation that never answers is failed by the poll ceiling,
 *      which brings it back here as a settled failure rather than a stuck row.
 *   3. **A supplier was asked and there is no generation to show for it.**
 *      {@see ProduceForProductionSlot} writes the supplier's name *before*
 *      calling, precisely so this case is visible. Nobody knows what came back.
 *      The occasion is closed as {@see ProductionSlotStatus::Unknown} — never
 *      retried, never recorded as a failure — because retrying risks paying
 *      twice and "it failed" may simply be untrue.
 *
 * Every reclaim is a guarded `UPDATE` carrying the same conditions that made it
 * safe, so two reapers racing produce one reclaim and the loser sees zero rows.
 * Running the reaper twice changes nothing the first run did not already do.
 */
final readonly class ReclaimProductionSlots
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @return array{reclaimed: int, unknown: int}
     */
    public function handle(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        // Computed here, never in SQL. ADR-0017: subtracting from a timestamp
        // in raw SQL underflows on an unsigned column and the engines disagree
        // about the arithmetic anyway.
        $cutoff = $now->copy()->subSeconds($this->leaseSeconds());

        $reclaimed = 0;
        $unknown = 0;

        foreach ($this->abandoned($cutoff) as $slot) {
            if ($slot->music_generation_id !== null) {
                // Case 2. Somebody is waiting on a supplier; the settler owns
                // this and will close it when there is an answer.
                continue;
            }

            if ($slot->attempted() !== []) {
                if ($this->markUnknown($slot, $cutoff)) {
                    $unknown++;
                }

                continue;
            }

            if ($this->reclaim($slot, $cutoff)) {
                $reclaimed++;
            }
        }

        return ['reclaimed' => $reclaimed, 'unknown' => $unknown];
    }

    /**
     * Case 1 — nothing was spent, so the occasion is simply available again.
     *
     * The guard repeats every condition that made this safe. Between reading
     * the row and writing it a worker may have picked it up, recorded a
     * generation, or been reclaimed by another reaper, and each of those makes
     * the update match zero rows instead of undoing somebody's work.
     */
    private function reclaim(ProductionSlot $slot, Carbon $cutoff): bool
    {
        $taken = ProductionSlot::query()
            ->whereKey($slot->getKey())
            ->where('status', ProductionSlotStatus::Claimed->value)
            ->whereNull('music_generation_id')
            ->where(function ($query) use ($cutoff): void {
                // A CLAIMED row with no `claimed_at` is malformed -- claiming
                // always writes one -- and carries no lease at all. It is
                // reclaimable for the same reason the others are: nobody was
                // asked, so nothing is at risk.
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
            })
            ->update([
                'status' => ProductionSlotStatus::Pending->value,
                'claimed_by' => null,
                'claimed_at' => null,
            ]);

        if ($taken !== 1) {
            return false;
        }

        // Who held it is in the audit line, because the row no longer has it
        // and "which worker keeps dying" is the question this answers.
        $this->audit->record(
            AuditAction::ProductionOccasionReclaimed,
            subjectUuid: $slot->uuid,
            context: ['claimed_by' => $slot->claimed_by],
        );

        return true;
    }

    /**
     * Case 3 — a supplier was asked and the answer is lost.
     *
     * Settled rather than reclaimed, and settled as UNKNOWN rather than FAILED.
     * See {@see ProductionSlotStatus::Unknown}: this may have produced a track
     * somebody has been charged for, and the only honest next step is a person
     * looking at the supplier's own record.
     */
    private function markUnknown(ProductionSlot $slot, Carbon $cutoff): bool
    {
        $settled = ProductionSlot::query()
            ->whereKey($slot->getKey())
            ->where('status', ProductionSlotStatus::Claimed->value)
            ->whereNull('music_generation_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
            })
            ->update([
                'status' => ProductionSlotStatus::Unknown->value,
                // What happened, not what we know -- the status already says
                // the result was lost, and repeating it here would waste the
                // one field that can say why.
                'outcome_reason' => 'WORKER_LOST',
                'settled_at' => Carbon::now(),
            ]);

        if ($settled !== 1) {
            return false;
        }

        $this->audit->record(
            AuditAction::ProductionOccasionUnknown,
            subjectUuid: $slot->uuid,
            // The suppliers that were asked, so somebody knows whose dashboard
            // to open. Names only -- no endpoint and no key.
            //
            // Joined into a string rather than passed as a list: an audit
            // context is a map of named values, and Redaction drops entries
            // whose key is not a name -- which every element of a list has.
            // A list here would have been stored as nothing at all.
            context: [
                'attempted_providers' => implode(', ', $slot->attempted()),
                'claimed_by' => $slot->claimed_by,
            ],
        );

        return true;
    }

    /**
     * Occasions held past their lease, oldest claim first.
     *
     * The `id` tiebreak is ADR-0017's: two claims taken in the same second must
     * come back in the same order on every pass, or a bounded batch silently
     * starves one of them for ever.
     *
     * @return iterable<ProductionSlot>
     */
    private function abandoned(Carbon $cutoff): iterable
    {
        return ProductionSlot::query()
            ->where('status', ProductionSlotStatus::Claimed->value)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
            })
            ->orderBy('claimed_at')
            ->orderBy('id')
            ->limit($this->batch())
            ->get();
    }

    public function leaseSeconds(): int
    {
        $configured = config('production.claim_lease_seconds');

        // A lease of zero or less would reclaim a claim the instant it was
        // taken, which is worse than no reaper: two workers on one occasion.
        return is_int($configured) && $configured > 0 ? $configured : 3600;
    }

    private function batch(): int
    {
        $configured = config('production.reclaim_batch');

        return is_int($configured) && $configured > 0 ? $configured : 100;
    }
}
