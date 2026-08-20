<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;

/**
 * Closing an occasion with what actually happened to it.
 *
 * {@see ProduceForProductionSlot} starts a generation and deliberately leaves
 * the slot CLAIMED, because starting is not producing. This is the other half:
 * it reads what the supplier eventually said and writes it down.
 *
 * **A supplier failing must not lose the occasion.** That is the requirement
 * this class exists for, and it is not satisfied by recording the failure — a
 * plan that wanted a track on the 14th still wants one, and a supplier having a
 * bad afternoon is not a reason for the 14th to be empty for ever. So a failed
 * generation is re-offered to the next supplier that could serve it and has not
 * already been tried, and only an occasion nobody will take is failed.
 *
 * **Reconciliation, not an event.** Generation results arrive by polling, and a
 * listener would only fire on the runs that happened to be observed; a slot
 * whose generation failed while the queue was down would sit CLAIMED for ever
 * with nothing due to notice. Reading the current state of the row is the only
 * approach that recovers by itself, and it is the same shape the distribution
 * module already uses.
 *
 * Nothing here spends money except through the same
 * {@see StartMusicGeneration} every other caller uses, with the same ceiling,
 * the same circuit breaker and the same atomic claim.
 */
final readonly class SettleProductionSlot
{
    public function __construct(
        private ClaimProductionSlot $claims,
        private SelectGenerationProvider $selection,
        private StartMusicGeneration $starter,
    ) {}

    /**
     * @return ProductionSlot|null the settled slot, or null when there is nothing
     *                             yet to settle it with
     */
    public function handle(ProductionSlot $slot): ?ProductionSlot
    {
        if ($slot->status !== ProductionSlotStatus::Claimed) {
            // Pending slots have not started and settled ones are finished.
            // Re-settling one would rewrite how it actually ended.
            return null;
        }

        $generation = $slot->generation;

        if (! $generation instanceof MusicGeneration) {
            // Claimed with nothing to show for it. A worker died between the
            // claim and the request, or between the request and recording it.
            // Left alone rather than failed: the occasion has not been spent,
            // and PROD-005's reaper is what should decide when a claim is
            // stale enough to take back.
            return null;
        }

        return match ($generation->status) {
            MusicGenerationStatus::Completed => $this->claims->complete($slot, 'GENERATION_COMPLETED'),
            MusicGenerationStatus::Cancelled => $this->claims->cancel($slot, 'GENERATION_CANCELLED'),
            MusicGenerationStatus::Failed => $this->reroute($slot),
            // Draft, queued, processing. Still in flight, and a slot settled
            // while its generation was still running would say the occasion
            // was over before the audio existed.
            default => null,
        };
    }

    /**
     * Offer the occasion to somebody else.
     *
     * The suppliers already tried are on the slot, so this cannot hand the work
     * back to the one that just failed. When there is nobody left, the occasion
     * is failed carrying the reason it ran out — not the last supplier's
     * excuse, which would name a supplier that was merely last.
     */
    private function reroute(ProductionSlot $slot): ProductionSlot
    {
        $previous = $slot->generation;
        $prompt = $previous instanceof MusicGeneration ? $previous->prompt : '';
        $required = StartMusicGeneration::requires(null, false);

        foreach ($this->selection->candidates($required, $slot->attempted()) as $name) {
            $attempted = $slot->attempted();
            $attempted[] = $name;
            $slot->forceFill(['attempted_providers' => array_values(array_unique($attempted))])->save();

            try {
                $generation = $this->starter->handle(
                    // The same request, to a different supplier. Re-deriving
                    // the prompt from the plan would let an edit made since
                    // change what this occasion asked for halfway through.
                    prompt: $prompt,
                    providerName: $name,
                );
            } catch (GenerationException|UnknownGenerationProvider) {
                continue;
            }

            $slot->forceFill(['music_generation_id' => $generation->getKey()])->save();
            SubmitMusicGenerationJob::dispatch($generation->uuid);

            // Still CLAIMED. The occasion is in flight again, with a different
            // supplier, and will come back through here when that one answers.
            return $slot->refresh();
        }

        return $this->claims->fail($slot, 'GENERATION_FAILED');
    }
}
