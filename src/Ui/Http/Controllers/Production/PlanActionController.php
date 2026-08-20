<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Production;

use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Exceptions\ProductionPlanException;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\ClaimProductionSlot;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Ui\Http\Requests\Production\SetAutonomyRequest;

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
