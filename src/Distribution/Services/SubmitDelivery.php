<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributionIdempotencyKey;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Exceptions\SubmissionLookupUnsupported;
use SaniTube\Distribution\Exceptions\SubmissionNotSent;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use Throwable;

/**
 * Handing a release to a distributor, once.
 *
 * **The one irreversible act in the platform.** Everything else — importing,
 * analysing, promoting, generating, assembling — happens inside SaniTube and
 * can be undone. A submitted release is in somebody else's system, on its way
 * to stores, and a duplicate submission is what gets a label's whole catalogue
 * flagged rather than one release.
 *
 * So there are three separate guards, and each covers a case the others do not:
 *
 *   1. **`UNIQUE (release_id, provider)`** — the database refuses a second
 *      delivery row for the same pair, whatever the code does.
 *   2. **A guarded status transition** — claiming the delivery with a
 *      conditional `UPDATE` means two concurrent submitters cannot both
 *      proceed, which reading-then-writing would allow.
 *   3. **A stable idempotency key** — derived from the release uuid and the
 *      distributor name, so a retry after a timeout, where SaniTube does not
 *      know whether the package arrived, sends the same key and a distributor
 *      that honours it recognises the repeat.
 *
 * Only a READY release may be delivered. A draft is still being assembled, and
 * handing one over is how a half-built package reaches stores.
 */
final readonly class SubmitDelivery
{
    public function __construct(
        private DistributorManager $distributors,
        private ValidateDelivery $validator,
    ) {}

    /**
     * Create — or return — the delivery record for this release and distributor.
     *
     * Separate from submission so that a label can validate, look at the
     * result, and come back later without anything having been handed over.
     */
    public function open(Release $release, ?string $provider = null): DistributionDelivery
    {
        $distributor = $this->distributorFor($provider);

        $existing = DistributionDelivery::query()
            ->where('release_id', $release->id)
            ->where('provider', $distributor->name())
            ->first();

        if ($existing instanceof DistributionDelivery) {
            return $existing;
        }

        return DistributionDelivery::query()->create([
            'release_id' => $release->id,
            'provider' => $distributor->name(),
            'status' => DistributionDeliveryStatus::Draft,
            // Derived once, never regenerated. See DistributionIdempotencyKey.
            'idempotency_key' => DistributionIdempotencyKey::for($release, $distributor->name()),
        ]);
    }

    public function handle(Release $release, ?string $provider = null): DistributionDelivery
    {
        $distributor = $this->distributorFor($provider);

        if (! $distributor->isAvailable()) {
            throw DistributionException::distributorUnavailable($distributor->name());
        }

        if ($release->status !== ReleaseStatus::Ready) {
            throw DistributionException::releaseNotReady();
        }

        $delivery = $this->open($release, $distributor->name());

        if (! $delivery->status->isSubmittable()) {
            throw DistributionException::alreadySubmitted($delivery->status);
        }

        $verdict = $this->validator->handle($release, $distributor);

        if ($verdict->has('DISTRIBUTOR_UNREACHABLE')) {
            // An outage is not a rejection. The package may be perfectly
            // acceptable; nobody could ask. FAILED is retryable and keeps the
            // same idempotency key, where REJECTED would read as a verdict the
            // distributor never gave.
            return $this->fail($delivery, implode('; ', $verdict->messages()), hrtime(true));
        }

        if (! $verdict->isValid()) {
            $this->record($delivery, DistributionAction::Validate, DistributionAttemptOutcome::Rejected, implode('; ', $verdict->messages()));

            throw DistributionException::rejectedByDistributor($verdict->messages());
        }

        // The claim. A conditional UPDATE is atomic on every engine in the
        // matrix; reading the status and then writing it would let two
        // concurrent submitters both get through.
        $claimed = DistributionDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', array_map(
                static fn (DistributionDeliveryStatus $s): string => $s->value,
                array_filter(
                    DistributionDeliveryStatus::cases(),
                    static fn (DistributionDeliveryStatus $s): bool => $s->isSubmittable(),
                ),
            ))
            ->update(['status' => DistributionDeliveryStatus::Submitting->value]);

        if ($claimed === 0) {
            throw DistributionException::alreadySubmitted($delivery->refresh()->status);
        }

        return $this->send($delivery->refresh(), $release, $distributor);
    }

    private function send(
        DistributionDelivery $delivery,
        Release $release,
        Distributor $distributor,
    ): DistributionDelivery {
        $startedAt = hrtime(true);

        try {
            // Preparing and submitting are separate calls because uploading
            // masters is the slow, resumable part and a retry must not repeat
            // it. Both carry the same key.
            //
            // They are also in separate try blocks, and that separation is
            // DIST-001-H1's central claim: a failure while *preparing* means
            // nothing was submitted, so a retry is safe. A failure while
            // *submitting* means the request may have arrived.
            $distributor->prepareRelease($release, $delivery->idempotency_key);
        } catch (Throwable $exception) {
            // Nothing was handed over. FAILED, retryable, same key.
            return $this->fail($delivery, $exception->getMessage(), $startedAt);
        }

        try {
            $submission = $distributor->submitRelease($release, $delivery->idempotency_key);
        } catch (SubmissionNotSent $exception) {
            // The adapter *knows* the request never left: DNS, refused
            // connection, a rejected handshake. The package demonstrably did
            // not arrive, so this is an ordinary retryable failure.
            return $this->fail($delivery, $exception->getMessage(), $startedAt);
        } catch (Throwable $exception) {
            // Everything else. A read timeout, a reset connection, a 502 from
            // a gateway that had already forwarded the request — all of them
            // are compatible with the distributor holding the package.
            //
            // FAILED here would offer a retry, and a retry against a
            // distributor that does not honour idempotency keys is a *second*
            // delivery: the exact outcome this module exists to prevent. The
            // stable key is a mitigation, not a guarantee, because no contract
            // can make a provider honour it.
            return $this->unconfirmed($delivery, $exception->getMessage(), $startedAt);
        }

        if (! $submission->accepted) {
            $delivery->forceFill([
                'status' => DistributionDeliveryStatus::Rejected,
                'failure_reason' => $submission->failureReason,
            ])->save();

            $this->record(
                $delivery,
                DistributionAction::Submit,
                DistributionAttemptOutcome::Rejected,
                $submission->failureReason,
                $startedAt,
            );

            return $delivery->refresh();
        }

        return DB::transaction(function () use ($delivery, $submission, $startedAt): DistributionDelivery {
            $delivery->forceFill([
                'status' => DistributionDeliveryStatus::Submitted,
                'external_release_id' => $submission->externalReleaseId,
                'failure_reason' => null,
                'submitted_at' => Carbon::now(),
                'last_synced_at' => Carbon::now(),
            ])->save();

            $this->record(
                $delivery,
                DistributionAction::Submit,
                DistributionAttemptOutcome::Succeeded,
                'Accepted by the distributor.',
                $startedAt,
            );

            return $delivery->refresh();
        });
    }

    /**
     * Record that SaniTube does not know what happened.
     *
     * Deliberately *not* `fail()` with a different word on it: the outcome
     * column is what later code branches on, and a row that says FAILED is a
     * row something will eventually retry.
     */
    private function unconfirmed(DistributionDelivery $delivery, string $reason, int $startedAt): DistributionDelivery
    {
        $delivery->forceFill([
            'status' => DistributionDeliveryStatus::SubmittedUnconfirmed,
            'failure_reason' => $reason,
        ])->save();

        $this->record(
            $delivery,
            DistributionAction::Submit,
            DistributionAttemptOutcome::Unknown,
            $reason,
            $startedAt,
        );

        return $delivery->refresh();
    }

    private function fail(DistributionDelivery $delivery, string $reason, int $startedAt): DistributionDelivery
    {
        $delivery->forceFill([
            'status' => DistributionDeliveryStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        $this->record($delivery, DistributionAction::Submit, DistributionAttemptOutcome::Failed, $reason, $startedAt);

        return $delivery->refresh();
    }

    private function record(
        DistributionDelivery $delivery,
        DistributionAction $action,
        DistributionAttemptOutcome $outcome,
        ?string $summary = null,
        ?int $startedAt = null,
    ): void {
        DistributionAttempt::query()->create([
            'delivery_id' => $delivery->id,
            'action' => $action,
            'outcome' => $outcome,
            'idempotency_key' => $delivery->idempotency_key,
            // A summary, never the raw payload: distributor responses quote
            // the request, and the request is signed.
            'response_summary' => $summary,
            'duration_ms' => $startedAt === null ? null : (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    private function distributorFor(?string $provider): Distributor
    {
        return $provider === null
            ? $this->distributors->default()
            : $this->distributors->distributor($provider);
    }

    /**
     * Bring the local record up to date with what the distributor says.
     */
    public function sync(DistributionDelivery $delivery): DistributionDelivery
    {
        if ($delivery->external_release_id === null) {
            // Never handed over. Asking about an identifier the distributor
            // has never issued answers nothing.
            return $delivery;
        }

        $distributor = $this->distributors->distributor($delivery->provider);
        $startedAt = hrtime(true);

        try {
            $reported = $distributor->deliveryStatus($delivery->external_release_id);
        } catch (Throwable $exception) {
            // A transient outage must not move the delivery. The next sync may
            // well succeed, and inventing a status from a failed request is
            // how a local record starts disagreeing with reality.
            $this->record($delivery, DistributionAction::Poll, DistributionAttemptOutcome::Failed, $exception->getMessage(), $startedAt);

            return $delivery;
        }

        $delivery->forceFill([
            'status' => $this->localStatusFor($reported, $delivery),
            'last_synced_at' => Carbon::now(),
            'delivered_at' => $reported === DeliveryStatus::Accepted
                ? ($delivery->delivered_at ?? Carbon::now())
                : $delivery->delivered_at,
            'live_at' => $reported === DeliveryStatus::Live
                ? ($delivery->live_at ?? Carbon::now())
                : $delivery->live_at,
        ])->save();

        $this->record($delivery, DistributionAction::Poll, DistributionAttemptOutcome::Succeeded, $reported->value, $startedAt);

        return $delivery->refresh();
    }

    private function localStatusFor(DeliveryStatus $reported, DistributionDelivery $delivery): DistributionDeliveryStatus
    {
        return match ($reported) {
            DeliveryStatus::Draft, DeliveryStatus::Submitted, DeliveryStatus::InReview => DistributionDeliveryStatus::Submitted,
            DeliveryStatus::Accepted => DistributionDeliveryStatus::Accepted,
            DeliveryStatus::Live => DistributionDeliveryStatus::Live,
            DeliveryStatus::Rejected => DistributionDeliveryStatus::Rejected,
            DeliveryStatus::TakedownRequested => DistributionDeliveryStatus::TakedownRequested,
            DeliveryStatus::TakenDown => DistributionDeliveryStatus::TakenDown,
            DeliveryStatus::Failed => DistributionDeliveryStatus::Failed,
        };
    }

    /**
     * Ask the distributor whether it actually received the package.
     *
     * The way out of `SUBMITTED_UNCONFIRMED` that does not need a person, and
     * the reason `findSubmission()` exists on the contract. Three answers,
     * three different outcomes:
     *
     *   - **it holds the submission** — it arrived. The reference is adopted
     *     and the delivery becomes SUBMITTED, which is what it would have been
     *     had the response come back.
     *   - **it holds nothing** — the request never landed. FAILED, retryable,
     *     same key.
     *   - **it cannot look** — nothing moves. `SUBMITTED_UNCONFIRMED` stays,
     *     and a person has to check the distributor's own dashboard.
     *
     * A transport failure while *asking* is the third case, not the second.
     * "The lookup timed out" is not evidence of an empty account.
     */
    public function reconcile(DistributionDelivery $delivery): DistributionDelivery
    {
        if (! $delivery->status->isUnknown()) {
            throw DistributionException::notReconcilable($delivery->status);
        }

        $distributor = $this->distributors->distributor($delivery->provider);
        $startedAt = hrtime(true);

        try {
            $found = $distributor->findSubmission($delivery->idempotency_key);
        } catch (SubmissionLookupUnsupported $exception) {
            $this->record($delivery, DistributionAction::Reconcile, DistributionAttemptOutcome::Unknown, $exception->getMessage(), $startedAt);

            return $delivery;
        } catch (Throwable $exception) {
            // The lookup itself failed. Still unknown — and moving the row on
            // a failed question would be inventing an answer.
            $this->record($delivery, DistributionAction::Reconcile, DistributionAttemptOutcome::Unknown, $exception->getMessage(), $startedAt);

            return $delivery;
        }

        if (! $found instanceof DistributorSubmission) {
            $this->record($delivery, DistributionAction::Reconcile, DistributionAttemptOutcome::Succeeded, 'The distributor holds nothing under this key.', $startedAt);

            return $this->fail($delivery, 'The distributor confirmed it never received this submission.', $startedAt);
        }

        $delivery->forceFill([
            'status' => DistributionDeliveryStatus::Submitted,
            'external_release_id' => $found->externalReleaseId,
            'failure_reason' => null,
            'submitted_at' => $delivery->submitted_at ?? Carbon::now(),
            'last_synced_at' => Carbon::now(),
        ])->save();

        $this->record($delivery, DistributionAction::Reconcile, DistributionAttemptOutcome::Succeeded, 'The distributor holds this submission.', $startedAt);

        return $delivery->refresh();
    }

    /**
     * A person has looked, and says what they found.
     *
     * The escape hatch for a distributor that cannot be asked. It exists
     * because the alternative is a row stuck forever — and being stuck is an
     * honest description of the world, but it is not a workflow.
     *
     * `$decidedBy` is required and recorded. A hand-entered external reference
     * is the one place in this module where a value the platform never
     * received from a distributor is written as though it had been, and a
     * record of who typed it is the least that owes.
     *
     * `$arrived === false` returns the delivery to FAILED rather than deleting
     * anything: the attempt history is what makes the decision reviewable.
     */
    public function resolveManually(
        DistributionDelivery $delivery,
        bool $arrived,
        ?string $externalReleaseId,
        int $decidedBy,
        string $note,
    ): DistributionDelivery {
        if (! $delivery->status->isUnknown()) {
            throw DistributionException::notReconcilable($delivery->status);
        }

        if ($arrived && ($externalReleaseId === null || trim($externalReleaseId) === '')) {
            // "It arrived but I cannot say under what reference" leaves the
            // delivery unpollable and untakedownable — a SUBMITTED row nobody
            // can ever ask about again.
            throw DistributionException::manualResolutionNeedsReference();
        }

        if (trim($note) === '') {
            throw DistributionException::manualResolutionNeedsNote();
        }

        $startedAt = hrtime(true);

        if ($arrived) {
            $delivery->forceFill([
                'status' => DistributionDeliveryStatus::Submitted,
                'external_release_id' => trim((string) $externalReleaseId),
                'failure_reason' => null,
                'submitted_at' => $delivery->submitted_at ?? Carbon::now(),
            ])->save();
        } else {
            $delivery->forceFill([
                'status' => DistributionDeliveryStatus::Failed,
                'failure_reason' => 'Resolved by a person: the distributor never received this submission.',
            ])->save();
        }

        $this->recordDecision($delivery, $decidedBy, $arrived, $note, $startedAt);

        return $delivery->refresh();
    }

    private function recordDecision(
        DistributionDelivery $delivery,
        int $decidedBy,
        bool $arrived,
        string $note,
        int $startedAt,
    ): void {
        DistributionAttempt::query()->create([
            'delivery_id' => $delivery->id,
            'action' => DistributionAction::Reconcile,
            'outcome' => DistributionAttemptOutcome::Succeeded,
            'idempotency_key' => $delivery->idempotency_key,
            'decided_by' => $decidedBy,
            'response_summary' => sprintf(
                'Resolved by a person as %s. %s',
                $arrived ? 'received' : 'never received',
                trim($note),
            ),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    /**
     * Ask for the release to be pulled from stores.
     */
    public function requestTakedown(DistributionDelivery $delivery): DistributionDelivery
    {
        if (! $delivery->status->isTakedownable() || $delivery->external_release_id === null) {
            throw DistributionException::notTakedownable($delivery->status);
        }

        $distributor = $this->distributors->distributor($delivery->provider);
        $startedAt = hrtime(true);

        try {
            $distributor->requestTakedown($delivery->external_release_id, $delivery->idempotency_key);
        } catch (Throwable $exception) {
            $this->record($delivery, DistributionAction::Takedown, DistributionAttemptOutcome::Failed, $exception->getMessage(), $startedAt);

            throw $exception;
        }

        $delivery->forceFill(['status' => DistributionDeliveryStatus::TakedownRequested])->save();

        $this->record($delivery, DistributionAction::Takedown, DistributionAttemptOutcome::Succeeded, null, $startedAt);

        return $delivery->refresh();
    }
}
