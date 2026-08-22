<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Production;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Exceptions\ProductionPlanException;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\ClaimProductionSlot;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Ui\Http\Requests\Production\SetAutonomyRequest;
use SaniTube\Ui\Http\Requests\Production\WritePlanRequest;

/**
 * The four things a person can do to an autonomous plan.
 *
 * **Stopping is the one that matters and it is the one that must always work.**
 * A planner that decides unattended that more music should exist, and pays for
 * it, needs a way to be stopped that does not depend on the operator having
 * shell access — which until this screen it did.
 *
 * Named actions, never a settable status. `PATCH {status: ACTIVE}` would
 * present resuming as an assignment and would let a form reach `EXHAUSTED`,
 * which is a conclusion the platform draws rather than a state anybody sets.
 *
 * Every one is audited, because the questions after a surprising month are
 * *who* granted autonomy and *when*, and a plan row holds only its current
 * state.
 */
final class PlanActionController
{
    /**
     * Making a plan.
     *
     * PROD-002. `WriteProductionPlan::create` has existed since PROD-001 and
     * had no caller outside its own tests — no controller, no console command,
     * no seeder. Neither did `WriteEditorialProfile`, which a plan requires.
     * So the planner, the one part of SaniTube that acts unattended, could not
     * be started from inside the product at all: a working installation had a
     * production screen that could only ever be empty.
     *
     * A plan starts `ACTIVE`, which is the service's decision and worth
     * repeating here because it is the surprising one: creating a plan is a
     * deliberate act, and one that arrived paused would be a plan somebody has
     * to remember to start — which is how a body of work quietly never
     * happens.
     */
    public function store(
        WritePlanRequest $request,
        WriteProductionPlan $plans,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $profile = EditorialProfile::query()->where('uuid', $request->profileUuid())->first();

        if (! $profile instanceof EditorialProfile) {
            return back()->withErrors(['production' => 'PLAN_PROFILE_UNKNOWN']);
        }

        /** @var User $author */
        $author = $request->user();

        try {
            $plan = $plans->create($profile, $request->planAttributes(), $author);
        } catch (ProductionPlanException $exception) {
            $audit->refused(AuditAction::ProductionPlanCreated, $exception->reason);

            return back()->withErrors(['production' => $exception->reason]);
        }

        // The terms travel in the context. "Who made a plan" without "how
        // often, and how many" is the half of the answer that does not explain
        // a month of generations.
        $audit->record(
            AuditAction::ProductionPlanCreated,
            subjectUuid: $plan->uuid,
            context: [
                'autonomy_mode' => $plan->autonomy_mode->value,
                ...$this->terms($plan),
            ],
        );

        return back()->with('status', 'production.plan_created');
    }

    /**
     * Correcting a plan's terms.
     *
     * Not its status: that is what `pause`, `resume` and `setAutonomy` are,
     * and a general update reaching `status` would put `EXHAUSTED` — a
     * conclusion the platform draws — within reach of a form.
     */
    public function update(
        WritePlanRequest $request,
        ProductionPlan $plan,
        WriteProductionPlan $plans,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $profile = null;
        $uuid = $request->profileUuid();

        if ($uuid !== null) {
            $profile = EditorialProfile::query()->where('uuid', $uuid)->first();

            if (! $profile instanceof EditorialProfile) {
                return back()->withErrors(['production' => 'PLAN_PROFILE_UNKNOWN']);
            }
        }

        $before = $this->terms($plan);

        try {
            $plan = $plans->update($plan, $request->planAttributes(), $profile);
        } catch (ProductionPlanException $exception) {
            $audit->refused(AuditAction::ProductionPlanUpdated, $exception->reason, $plan->uuid);

            return back()->withErrors(['production' => $exception->reason]);
        }

        $audit->record(
            AuditAction::ProductionPlanUpdated,
            subjectUuid: $plan->uuid,
            context: [
                ...$this->terms($plan),
                // The previous pair as well, because raising a target is what
                // restarts an exhausted plan, and the size of the change is
                // the fact somebody is looking for.
                //
                // Flat rather than nested under a `was` key: the audit
                // redaction drops numeric keys, so a list inside a context
                // arrives as an empty one — a record that says a change
                // happened and nothing about it.
                'was_cadence_days' => $before['cadence_days'],
                'was_target_track_count' => $before['target_track_count'],
            ],
        );

        return back()->with('status', 'production.plan_updated');
    }

    /**
     * The two numbers that decide how often this installation pays a supplier.
     *
     * Rendered as strings, with the vocabulary the screens already use for
     * their absence. A null in an audit context is neither a string nor a
     * number and arrives redacted — which would turn "the target was cleared"
     * into a placeholder somebody has to guess at.
     *
     * @return array<string, string>
     */
    private function terms(ProductionPlan $plan): array
    {
        return [
            'cadence_days' => $plan->cadence_days === null ? 'NO_CADENCE' : (string) $plan->cadence_days,
            'target_track_count' => $plan->target_track_count === null
                ? 'NO_TARGET'
                : (string) $plan->target_track_count,
        ];
    }

    public function pause(ProductionPlan $plan, WriteProductionPlan $plans, RecordAuditEvent $audit): RedirectResponse
    {
        // Allowed from any state, including one the platform stopped itself.
        // A pause button that refused because the plan was already stopped
        // would make somebody work out which kind of stopped it was before
        // they could be sure it would not start again.
        $plans->pause($plan);

        $audit->record(AuditAction::ProductionPlanPaused, subjectUuid: $plan->uuid);

        return back()->with('status', 'production.plan_paused');
    }

    public function resume(ProductionPlan $plan, WriteProductionPlan $plans, RecordAuditEvent $audit): RedirectResponse
    {
        try {
            $plans->resume($plan);
        } catch (ProductionPlanException $exception) {
            // An exhausted plan needs its target raised and a disabled one
            // needs reconsidering. Neither is something a resume button should
            // do silently on somebody's behalf.
            $audit->refused(AuditAction::ProductionPlanResumed, $exception->reason, $plan->uuid);

            return back()->withErrors(['production' => $exception->reason]);
        }

        $audit->record(AuditAction::ProductionPlanResumed, subjectUuid: $plan->uuid);

        return back()->with('status', 'production.plan_resumed');
    }

    public function setAutonomy(
        SetAutonomyRequest $request,
        ProductionPlan $plan,
        WriteProductionPlan $plans,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $mode = AutonomyMode::from($request->mode());

        try {
            $plans->setAutonomy($plan, $mode);
        } catch (ProductionPlanException $exception) {
            // The locked mode. Refused in the enum and again in the service;
            // this is the third place, and it exists so the screen says "not
            // available yet" rather than showing a form that does nothing.
            $audit->refused(AuditAction::ProductionAutonomyChanged, $exception->reason, $plan->uuid);

            return back()->withErrors(['production' => $exception->reason]);
        }

        // The mode is recorded in the audit context, because "who changed the
        // autonomy" without "to what" is half an answer.
        $audit->record(
            AuditAction::ProductionAutonomyChanged,
            subjectUuid: $plan->uuid,
            context: ['autonomy_mode' => $mode->value],
        );

        return back()->with('status', 'production.autonomy_changed');
    }

    /**
     * A person calling off one occasion.
     *
     * Allowed from pending **and** from claimed, which is `ClaimProductionSlot`'s
     * own rule: a worker that has taken a slot and is waiting on a supplier is
     * exactly the situation somebody wants to call off, and a cancel that only
     * worked before anything picked it up would be one that never works when it
     * matters.
     */
    public function cancelOccasion(
        ProductionSlot $slot,
        ClaimProductionSlot $claims,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $cancelled = $claims->cancel($slot);

        if (! $cancelled instanceof ProductionSlot) {
            $audit->refused(AuditAction::ProductionOccasionCancelled, 'ALREADY_SETTLED', $slot->uuid);

            return back()->withErrors(['production' => 'ALREADY_SETTLED']);
        }

        $audit->record(AuditAction::ProductionOccasionCancelled, subjectUuid: $slot->uuid);

        return back()->with('status', 'production.occasion_cancelled');
    }
}
