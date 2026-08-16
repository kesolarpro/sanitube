<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
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

        return response()->json([
            'provider' => $provider,
            'valid' => $verdict->isValid(),
            'errors' => $verdict->errors,
            'warnings' => $verdict->warnings,
        ]);
    }

    /**
     * The one irreversible act in the platform.
     */
    public function submit(Release $release, string $provider, SubmitDelivery $submitter): RedirectResponse
    {
        try {
            $delivery = $submitter->handle($release, $provider);
        } catch (DistributionException $exception) {
            return $this->refused($exception->reason);
        } catch (RuntimeException) {
            return $this->refused('UNKNOWN_DISTRIBUTOR');
        }

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

    public function requestTakedown(DistributionDelivery $delivery, SubmitDelivery $submitter): RedirectResponse
    {
        try {
            $submitter->requestTakedown($delivery);
        } catch (DistributionException $exception) {
            return $this->refused($exception->reason);
        } catch (Throwable) {
            // The distributor could not be reached. The attempt is already
            // recorded by the service; what must not happen is the screen
            // reporting a takedown that was never asked for.
            return $this->refused('TAKEDOWN_UNREACHABLE');
        }

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
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'distribution.resolved');
    }

    private function refused(string $code): RedirectResponse
    {
        return back()->withErrors(['distribution' => $code]);
    }
}
