<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use Illuminate\Support\Carbon;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;

/**
 * Taking a slot, exactly once.
 *
 * **The claim is a guarded UPDATE and never a read-then-write.** Two workers
 * draining the same queue will both find the same pending slot; only an atomic
 * update can decide which of them gets it, and it is atomic on every engine in
 * the matrix. A worker that read the status, decided it was pending, and then
 * wrote would double every occasion the moment a second worker existed — and
 * this platform's whole reason for having slots is that occasions happen once.
 *
 * The claimer names itself. A slot is taken by a *process*, and the question
 * after an incident is which one — so the column holds a worker's name rather
 * than a user id.
 */
final readonly class ClaimProductionSlot
{
    /**
     * @return ProductionSlot|null null when somebody else got there first, or the
     *                             slot is no longer claimable, or its day has not come
     */
    public function claim(ProductionSlot $slot, string $worker, ?Carbon $now = null): ?ProductionSlot
    {
        // Asked before the claim rather than after: a slot opened for next
        // Tuesday is work scheduled, not work waiting, and a worker that
        // ignored this would do a month of production in an afternoon.
        if (! $slot->isDue($now)) {
            return null;
        }

        $claimed = ProductionSlot::query()
            ->whereKey($slot->id)
            ->where('status', ProductionSlotStatus::Pending->value)
            ->update([
                'status' => ProductionSlotStatus::Claimed->value,
                'claimed_by' => mb_substr($worker, 0, 128),
                'claimed_at' => Carbon::now(),
            ]);

        return $claimed === 1 ? $slot->refresh() : null;
    }

    /**
     * The work happened.
     */
    public function complete(ProductionSlot $slot, ?string $reason = null): ProductionSlot
    {
        return $this->settle($slot, ProductionSlotStatus::Completed, $reason);
    }

    /**
     * The platform decided there was nothing to do.
     *
     * **Not a failure**, and separate from one. An inventory that found enough
     * already, or a plan that reached its target between the slot opening and
     * being claimed, is the system working — and a screen that filed those
     * under failures would teach an operator to ignore the failure list.
     */
    public function skip(ProductionSlot $slot, string $reason): ProductionSlot
    {
        return $this->settle($slot, ProductionSlotStatus::Skipped, $reason);
    }

    /**
     * The work was attempted and did not finish.
     */
    public function fail(ProductionSlot $slot, string $reason): ProductionSlot
    {
        return $this->settle($slot, ProductionSlotStatus::Failed, $reason);
    }

    /**
     * A person calling it off.
     *
     * Allowed from pending **and** from claimed. A worker that has taken a slot
     * and is waiting on a provider is exactly the situation somebody wants to
     * call off, and a cancel that only worked before anything picked it up
     * would be a cancel that never works when it matters.
     *
     * A settled slot is left alone: re-settling one would rewrite how it
     * actually ended.
     */
    public function cancel(ProductionSlot $slot, ?string $reason = null): ?ProductionSlot
    {
        if ($slot->status->isSettled()) {
            return null;
        }

        return $this->settle($slot, ProductionSlotStatus::Cancelled, $reason);
    }

    private function settle(ProductionSlot $slot, ProductionSlotStatus $status, ?string $reason): ProductionSlot
    {
        $slot->forceFill([
            'status' => $status,
            'outcome_reason' => $reason === null ? null : mb_substr($reason, 0, 64),
            'settled_at' => Carbon::now(),
        ])->save();

        return $slot->refresh();
    }
}
