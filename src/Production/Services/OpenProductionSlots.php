<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;

/**
 * The scheduler. It opens slots, and it does nothing else.
 *
 * **This class must never invoke music generation, and that is the single most
 * important sentence about it.** A scheduler that generated directly would be a
 * cron entry that spends money in the dark: nothing would record that the
 * occasion existed, nothing could cancel it before it ran, a second run would
 * do the work twice, and the only evidence afterwards would be a bill and some
 * audio. `the_scheduler_never_reaches_generation` asserts the absence
 * mechanically, because this is a boundary that erodes by somebody adding one
 * convenient call.
 *
 * What it produces instead is a row: an occasion, with a date, that something
 * else may claim. That single indirection is what makes the work **idempotent**
 * (the unique key), **traceable** (the row is the record), **cancelable** (a
 * person can settle it before anything claims it) and **auditable** (every
 * transition is a state on a row rather than a side effect nobody saw).
 *
 * **Idempotence is the database's job, not this class's.** Checking for an
 * existing slot and then inserting is "usually once": two processes can both
 * find nothing and both insert. The unique index settles it, and the
 * constraint violation is caught and treated as success — because it means
 * exactly what this method wanted to be true.
 */
final readonly class OpenProductionSlots
{
    /**
     * Slots are opened **one occasion at a time**. Opening a year of them
     * would make a plan's cadence a promise the platform has already
     * committed to, and changing your mind would mean cancelling fifty rows;
     * counting the next date from the last slot gives the same rhythm with
     * one row outstanding.
     *
     * @return list<ProductionSlot> the slots this run opened, which is empty on
     *                              a second run in the same period
     */
    public function handle(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->startOfDay();
        $opened = [];

        $plans = ProductionPlan::query()
            ->where('status', ProductionPlanStatus::Active->value)
            // A query narrowing rather than a guard: `openFor()` refuses a
            // null cadence anyway, so removing this changes nothing an
            // observer could see. It stays because a manual plan is a plan
            // this scheduler has no business loading.
            ->whereNotNull('cadence_days')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            $slot = $this->openFor($plan, $now);

            if ($slot instanceof ProductionSlot) {
                $opened[] = $slot;
            }
        }

        return $opened;
    }

    /**
     * The next occasion for one plan, if it is time for one.
     */
    private function openFor(ProductionPlan $plan, Carbon $now): ?ProductionSlot
    {
        $cadence = $plan->cadence_days;

        if ($cadence === null || $cadence < 1) {
            return null;
        }

        $due = $this->nextDate($plan, $cadence, $now);

        // Opened on the day, never ahead of it.
        //
        // A horizon looks like a kindness and is an off-by-one: a slot opened
        // a day early is un-claimable anyway -- a worker checks the date --
        // so it buys nothing, and it shifts every subsequent occasion because
        // the next one is counted from this one's date. Written with a
        // one-day horizon first, and the cadence test is what caught it.
        if ($due->isAfter($now)) {
            return null;
        }

        try {
            return ProductionSlot::query()->create([
                'production_plan_id' => $plan->id,
                'scheduled_for' => $due,
                'status' => ProductionSlotStatus::Pending,
                'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process opened the same occasion between this one
            // deciding and inserting. That is precisely what the unique key is
            // for, and it means what this method wanted to be true already is
            // -- so it is success, and it returns nothing because this run did
            // not open it.
            return null;
        }
    }

    /**
     * When this plan is next due.
     *
     * Counted from the last slot rather than from the plan's creation, so a
     * plan paused for a month does not come back owing thirty slots. The
     * question a cadence answers is "how long since the last time", and a
     * scheduler that answered "how many should there have been by now" would
     * turn a pause into a backlog.
     */
    private function nextDate(ProductionPlan $plan, int $cadence, Carbon $now): Carbon
    {
        $last = ProductionSlot::query()
            ->where('production_plan_id', $plan->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->first();

        if (! $last instanceof ProductionSlot) {
            // Never produced anything. Due now, which is what somebody who
            // just created a plan expects.
            return $now->copy();
        }

        $due = $last->scheduled_for->copy()->addDays($cadence)->startOfDay();

        // Never in the past. A plan that fell behind catches up one occasion at
        // a time rather than opening every date it missed at once, for the same
        // reason the horizon is one day.
        return $due->isBefore($now) ? $now->copy() : $due;
    }
}
