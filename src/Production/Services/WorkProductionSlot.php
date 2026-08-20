<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\ProductionInventory;

/**
 * Taking one occasion and deciding what it is worth doing.
 *
 * **The inventory comes before anything else, and that is the whole point of
 * this class.** A worker that claimed a slot and generated would be the
 * blind-generation failure with an extra table in front of it: forty tracks
 * because forty is the number in the plan, thirty-eight of them already sitting
 * unreleased, and every one a paid call.
 *
 * So the order is fixed and short:
 *
 *   1. claim the occasion, atomically, or stop;
 *   2. re-check the plan, because a queue is a delay;
 *   3. **count what the plan already has**;
 *   4. skip, with a reason, unless more is genuinely wanted.
 *
 * **This class does not generate either.** It decides that generating is
 * warranted and says so; producing the audio is the next thing along, and
 * keeping the decision separate from the doing is what makes "why did the
 * platform make this" answerable from a row rather than from a log.
 *
 * A skip is **not a failure**. An inventory that found enough is the system
 * working, and the states are separate so a screen can say so.
 */
final readonly class WorkProductionSlot
{
    public function __construct(
        private ClaimProductionSlot $claims,
        private TakeProductionInventory $inventory,
    ) {}

    /**
     * @return ProductionInventory|null the count that justified the work, or null when
     *                                  the slot was not taken or was settled without it
     */
    public function handle(ProductionSlot $slot, string $worker): ?ProductionInventory
    {
        $claimed = $this->claims->claim($slot, $worker);

        if (! $claimed instanceof ProductionSlot) {
            // Somebody else got it, it is not due, or it is no longer
            // claimable. All three are ordinary.
            return null;
        }

        $plan = $claimed->plan;

        if ($plan === null || ! $plan->status->isRunnable()) {
            // A queue is a delay. The plan can be paused, exhausted or
            // stopped by the platform between the slot opening and a worker
            // reaching it, and this is the last point at which noticing that
            // is free.
            $this->claims->skip($claimed, 'PLAN_NOT_RUNNABLE');

            return null;
        }

        if (! $plan->mayGenerateUnattended()) {
            // The plan exists and is running, and nobody said the platform may
            // act on it alone. The occasion is still real -- a person can act
            // on it -- so it is skipped rather than failed.
            $this->claims->skip($claimed, 'AUTONOMY_NOT_GRANTED');

            return null;
        }

        $inventory = $this->inventory->handle($plan);
        $refusal = $inventory->refusal();

        if ($refusal !== null) {
            $this->claims->skip($claimed, $refusal);

            return null;
        }

        // Warranted. The slot stays CLAIMED: what happens next is generation,
        // and whatever does it settles this occasion when it knows how it
        // ended. Completing here would record that something was made before
        // anything was.
        return $inventory;
    }

    /**
     * Whether this slot is one a worker should look at at all.
     *
     * For a queue that is choosing what to hand out. Presentation-adjacent and
     * not a guard: {@see handle()} re-checks everything, because between
     * choosing and claiming there is a gap.
     */
    public function looksWorkable(ProductionSlot $slot): bool
    {
        return $slot->status === ProductionSlotStatus::Pending && $slot->isDue();
    }
}
