<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use RuntimeException;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Storage\CredentialRedactor;

/**
 * One handover, and every conversation it has had.
 *
 * **Nothing here talks to a distributor.** `isAvailable()` and `isSandbox()`
 * read configuration — the contract says so — and everything else comes from
 * rows SaniTube wrote itself. A screen that polled on render would make
 * refreshing a page an outbound request, and an outage would take the page
 * down with it rather than being reported on it. Asking the distributor is
 * `sync`, which is a button.
 *
 * Three states are kept apart and must never be flattened into each other:
 *
 *   - **Unknown** — the delivery has never been handed over, or nobody has
 *     synced since. `last_synced_at` is null and that is what is shown.
 *   - **Failed** — SaniTube could not reach the distributor. Retryable, and
 *     *not* a verdict the distributor gave.
 *   - **Rejected** — the distributor said no. A verdict.
 *
 * The distinction is the whole point of DIST-001's design and a screen that
 * drew FAILED and REJECTED with the same badge would undo it.
 */
final readonly class DeliveryDetailQuery
{
    /** Enough of a failure to act on; never enough to leak a URL. */
    private const REASON_LIMIT = 200;

    public function __construct(private DistributorManager $distributors) {}

    /**
     * @return array<string, mixed>
     */
    public function forDelivery(DistributionDelivery $delivery, User $viewer): array
    {
        $delivery->load('release');

        $release = $delivery->release;
        $mayDistribute = $viewer->role->canDistribute();
        $provider = $this->provider($delivery->provider);

        return [
            'uuid' => $delivery->uuid,
            'provider' => $delivery->provider,
            'status' => $delivery->status->value,

            // The question a label has is "did this reach them", not "what did
            // they call it". The reference itself identifies a record in
            // somebody else's account and stays here.
            'has_external_reference' => $delivery->external_release_id !== null,

            // Where that reference came from. A value a distributor returned
            // and one a person read off a dashboard and typed in are not the
            // same fact, and a screen that showed them identically would let
            // the second be read as provider-verified. The code travels; the
            // reference itself does not.
            'external_reference_source' => $delivery->external_reference_source?->value,

            'failure_reason' => $this->reason($delivery->failure_reason),

            'submitted_at' => $delivery->submitted_at?->toAtomString(),
            'delivered_at' => $delivery->delivered_at?->toAtomString(),
            'live_at' => $delivery->live_at?->toAtomString(),

            // Null means nobody has asked, which is not the same as "nothing
            // has changed". The screen says so rather than showing a date.
            'last_synced_at' => $delivery->last_synced_at?->toAtomString(),

            'created_at' => $delivery->created_at?->toAtomString(),

            'release' => $release === null ? null : [
                'uuid' => $release->uuid,
                'title' => $release->title,
                'status' => $release->status->value,
            ],

            'distributor' => $provider,

            'attempts' => $this->attempts($delivery),

            'actions' => [
                'may_distribute' => $mayDistribute,

                // Presentation, never authorisation. The route middleware
                // decides who may act; SubmitDelivery decides what the state
                // permits, and it re-checks every one of these.
                'can_submit' => $mayDistribute
                    && $delivery->status->isSubmittable()
                    && $provider['available'] === true,

                'can_sync' => $mayDistribute && $delivery->external_release_id !== null,

                'can_request_takedown' => $mayDistribute
                    && $delivery->status->isTakedownable()
                    && $delivery->external_release_id !== null,

                // DIST-001-H1. Both only exist while SaniTube cannot say what
                // happened, and neither is a retry: reconciling asks the
                // distributor what it holds, resolving records what a person
                // found when they looked.
                'can_reconcile' => $mayDistribute
                    && $delivery->status->isUnknown()
                    && $provider['available'] === true,

                'can_resolve_manually' => $mayDistribute && $delivery->status->isUnknown(),
            ],
        ];
    }

    /**
     * What the installation knows about this distributor, from configuration.
     *
     * An unresolvable name is reported as unavailable with `known: false`
     * rather than throwing. A delivery outlives a configuration change, and a
     * screen that 500s because somebody removed a provider from `config` is a
     * screen that hides the very record they need to look at.
     *
     * @return array<string, mixed>
     */
    private function provider(string $name): array
    {
        try {
            $distributor = $this->distributors->distributor($name);
        } catch (RuntimeException) {
            return ['name' => $name, 'known' => false, 'available' => false, 'sandbox' => null];
        }

        return [
            'name' => $distributor->name(),
            'known' => true,
            // Configuration, not a probe. See the class docblock.
            'available' => $distributor->isAvailable(),
            'sandbox' => $distributor->isSandbox(),
        ];
    }

    /**
     * The conversation, oldest first.
     *
     * `response_summary` is a summary the domain wrote, never a raw payload —
     * DIST-001 is explicit that distributor responses quote the request and
     * the request is signed. The outage path stores an exception message all
     * the same, so it is scrubbed as well as shortened; see {@see reason()}.
     *
     * @return list<array<string, mixed>>
     */
    private function attempts(DistributionDelivery $delivery): array
    {
        $rows = [];

        $attempts = $delivery->attempts()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        /** @var DistributionAttempt $attempt */
        foreach ($attempts as $attempt) {
            $rows[] = [
                'uuid' => $attempt->uuid,
                'action' => $attempt->action->value,
                'outcome' => $attempt->outcome->value,
                'summary' => $this->reason($attempt->response_summary),
                'duration_ms' => $attempt->duration_ms,
                'created_at' => $attempt->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * Scrubbed, then shortened — and that order is the correction.
     *
     * This used to take the first line and truncate it, which is what the
     * comment above still described as sufficient: "those quote URLs" was
     * known, and a length limit was the answer. A limit removes the *end* of a
     * message. A client's 403 arrives as
     * `PUT https://…?X-Amz-Signature=… failed`, comfortably inside the limit,
     * and that signature is a bearer credential.
     *
     * Writes are clean from now on; rows written before they were are already
     * in the column, and this is what covers them.
     */
    private function reason(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        $firstLine = strtok(trim($reason), "\n");

        if ($firstLine === false) {
            return null;
        }

        $clean = CredentialRedactor::scrub($firstLine);

        return $clean === null ? null : mb_strimwidth($clean, 0, self::REASON_LIMIT, '…');
    }
}
