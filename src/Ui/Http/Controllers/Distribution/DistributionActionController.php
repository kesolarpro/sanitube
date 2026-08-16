<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Services\ValidateDelivery;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Http\Requests\Distribution\ResolveDeliveryRequest;
use Throwable;

/**
 * The four things a person can do to a delivery.
 *
 * All of them go through DIST-001's services unchanged. Nothing here decides
 * whether a delivery may be submitted, whether a status may move, or what an
 * outage means — those are `SubmitDelivery`'s answers, and a second copy in a
 * controller is the copy that eventually disagrees on the one act that cannot
 * be undone.
 *
 * **`validate` creates nothing.** It is separated from `submit` for the reason
 * DIST-001 gives: a label must be able to see a distributor's verdict without
 * anything being handed over. It does not call `open()`, because a preflight
 * that left a DRAFT delivery row behind would turn "let me check" into a
 * record somebody later reads as an intention.
 *
 * Refusals travel as machine-readable codes through `withErrors`; the
 * interface translates them. `DistributionException` already carries one.
 *
 * **Three of the five are audited: submit, takedown and resolve.** They are
 * the ones that change something outside SaniTube or write a value the
 * platform never received. `sync` and `reconcile` ask the distributor a
 * question and record their own attempt rows; auditing them too would fill the
 * operator's log with polling and bury the three lines that matter.
 *
 * On a refusal the subject uuid is null rather than the release's: there is no
 * delivery, and recording a release uuid in a column that means "the delivery
 * this is about" would be a small lie that reads as a fact. The release and
 * the provider go in the context, where they are what they are.
 */
final class DistributionActionController
{
    public function verdict(
        Release $release,
        string $provider,
        DistributorManager $distributors,
        ValidateDelivery $validator,
    ): JsonResponse {
        try {
            $distributor = $distributors->distributor($provider);
        } catch (RuntimeException) {
            return response()->json(['code' => 'UNKNOWN_DISTRIBUTOR'], 422);
        }

        $verdict = $validator->handle($release, $distributor);

        // REL-002: issues, not sentences. The screen translates the codes;
        // the English never leaves the log.
        return response()->json(array_merge(['provider' => $provider], $verdict->toArray()));
    }

    /**
     * The one irreversible act in the platform.
     */
    public function submit(
        Release $release,
        string $provider,
        SubmitDelivery $submitter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $about = ['release' => $release->uuid, 'provider' => $provider];

        try {
            $delivery = $submitter->handle($release, $provider);
        } catch (DistributionException $exception) {
            $audit->refused(AuditAction::DeliverySubmitted, $exception->reason, context: $about);

            return $this->refused($exception->reason);
        } catch (RuntimeException) {
            $audit->refused(AuditAction::DeliverySubmitted, 'UNKNOWN_DISTRIBUTOR', context: $about);

            return $this->refused('UNKNOWN_DISTRIBUTOR');
        }

        $audit->record(AuditAction::DeliverySubmitted, subjectUuid: $delivery->uuid, context: $about);

        // To the delivery, not back to the list. Something now exists in
        // somebody else's system and the record of it is what a person needs
        // in front of them.
        return redirect()
            ->to('/distribution/'.$delivery->uuid)
            ->with('status', 'distribution.submitted');
    }

    public function sync(DistributionDelivery $delivery, SubmitDelivery $submitter): RedirectResponse
    {
        try {
            $submitter->sync($delivery);
        } catch (RuntimeException) {
            // The provider is no longer configured. `sync` itself never throws
            // on an outage — it records a failed attempt and leaves the status
            // alone, because inventing a status from a failed request is how a
            // local record starts disagreeing with reality.
            return $this->refused('UNKNOWN_DISTRIBUTOR');
        }

        return back()->with('status', 'distribution.synced');
    }

    public function requestTakedown(
        DistributionDelivery $delivery,
        SubmitDelivery $submitter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        try {
            $submitter->requestTakedown($delivery);
        } catch (DistributionException $exception) {
            $audit->refused(AuditAction::DeliveryTakedownRequested, $exception->reason, $delivery->uuid);

            return $this->refused($exception->reason);
        } catch (Throwable) {
            // The distributor could not be reached. The attempt is already
            // recorded by the service; what must not happen is the screen
            // reporting a takedown that was never asked for.
            $audit->refused(AuditAction::DeliveryTakedownRequested, 'TAKEDOWN_UNREACHABLE', $delivery->uuid);

            return $this->refused('TAKEDOWN_UNREACHABLE');
        }

        $audit->record(AuditAction::DeliveryTakedownRequested, subjectUuid: $delivery->uuid);

        return back()->with('status', 'distribution.takedown_requested');
    }

    /**
     * Ask the distributor whether it actually received the package.
     *
     * Not a retry, and the difference matters: a retry hands the package over
     * again, this asks a question. It is offered only while the answer is
     * unknown.
     */
    public function reconcile(DistributionDelivery $delivery, SubmitDelivery $submitter): RedirectResponse
    {
        try {
            $submitter->reconcile($delivery);
        } catch (DistributionException $exception) {
            return $this->refused($exception->reason);
        } catch (RuntimeException) {
            return $this->refused('UNKNOWN_DISTRIBUTOR');
        }

        return back()->with('status', 'distribution.reconciled');
    }

    /**
     * Record what a person found when they looked.
     *
     * The escape hatch for a distributor that cannot be asked. The reviewer is
     * named from the session, never from the request — a decision that says
     * who made it is only worth something if the caller could not choose.
     */
    public function resolve(
        ResolveDeliveryRequest $request,
        DistributionDelivery $delivery,
        SubmitDelivery $submitter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        /** @var User $decider */
        $decider = $request->user();

        try {
            $submitter->resolveManually(
                $delivery,
                arrived: $request->arrived(),
                externalReleaseId: $request->externalReleaseId(),
                decidedBy: $decider->getKey(),
                note: $request->note(),
            );
        } catch (DistributionException $exception) {
            $audit->refused(AuditAction::DeliveryReferenceDecided, $exception->reason, $delivery->uuid);

            return $this->refused($exception->reason);
        }

        // `arrived` and nothing else. Not the external reference the operator
        // typed and not their note: the reference is already on the delivery
        // with its MANUAL_OPERATOR provenance, and copying free text into a
        // table nobody scrubs afterwards is how an audit log accumulates
        // whatever somebody felt like writing.
        $audit->record(
            AuditAction::DeliveryReferenceDecided,
            subjectUuid: $delivery->uuid,
            context: ['arrived' => $request->arrived()],
        );

        return back()->with('status', 'distribution.resolved');
    }

    private function refused(string $code): RedirectResponse
    {
        return back()->withErrors(['distribution' => $code]);
    }
}
