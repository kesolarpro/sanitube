<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\Contracts\SupportsSubmissionLookup;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributionIdempotencyKey;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Enums\ExternalReferenceSource;
use SaniTube\Distribution\Exceptions\DistributionException;
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
                // Watched arrive. The ordinary case, and the only one where
                // the platform saw the distributor produce the value.
                'external_reference_source' => ExternalReferenceSource::ProviderResponse,
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

    /**
     * Move the delivery to FAILED and say which action failed.
     *
     * The action is a parameter rather than always `Submit` because
     * `reconcile()` also ends here, and an attempt row saying a *submission*
     * failed when what actually happened is that a reconciliation concluded
     * the package never arrived is a log that misdescribes the one
     * irreversible act in the platform. The history is what a person reads
     * when deciding whether to hand the release over again.
     */
    private function fail(
        DistributionDelivery $delivery,
        string $reason,
        int $startedAt,
        DistributionAction $action = DistributionAction::Submit,
    ): DistributionDelivery {
        $delivery->forceFill([
            'status' => DistributionDeliveryStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        $this->record($delivery, $action, DistributionAttemptOutcome::Failed, $reason, $startedAt);

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
     *     and a person has to check the distributor's own dashboard. Asked of
     *     the *type*: an adapter that does not implement
     *     {@see SupportsSubmissionLookup} is this answer, and is never called.
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

        // Asked of the type, before any call. An adapter for a provider with
        // no lookup endpoint does not implement the capability, and that is
        // the answer — "I cannot look" no longer has to travel as an exception
        // thrown from a method the adapter was obliged to declare.
        if (! $distributor instanceof SupportsSubmissionLookup) {
            $this->record(
                $delivery,
                DistributionAction::Reconcile,
                DistributionAttemptOutcome::Unknown,
                sprintf('The [%s] distributor cannot be asked what it holds.', $distributor->name()),
                $startedAt,
            );

            return $delivery;
        }

        try {
            $found = $distributor->findSubmission($delivery->idempotency_key);
        } catch (Throwable $exception) {
            // The lookup itself failed. Still unknown — and moving the row on
            // a failed question would be inventing an answer.
            $this->record($delivery, DistributionAction::Reconcile, DistributionAttemptOutcome::Unknown, $exception->getMessage(), $startedAt);

            return $delivery;
        }

        // Claimed only now that there is an answer to write. Everything above
        // this point either left the row alone or recorded that nobody could
        // say — neither needs the row held.
        return DB::transaction(function () use ($delivery, $found, $startedAt): DistributionDelivery {
            $held = $this->claimUnknown($delivery);

            if (! $found instanceof DistributorSubmission) {
                // One row, not two. "The lookup succeeded" and "the delivery
                // failed" are the same event seen twice, and an append-only
                // log that says an outcome twice is one somebody reconciles
                // by hand.
                return $this->fail(
                    $held,
                    'The distributor confirmed it never received this submission.',
                    $startedAt,
                    DistributionAction::Reconcile,
                );
            }

            $held->forceFill([
                'status' => DistributionDeliveryStatus::Submitted,
                'external_release_id' => $found->externalReleaseId,
                // From the provider, but after a lost response rather than in
                // one. Worth telling apart when reconstructing what happened.
                'external_reference_source' => ExternalReferenceSource::ProviderLookup,
                'failure_reason' => null,
                'submitted_at' => $held->submitted_at ?? Carbon::now(),
                'last_synced_at' => Carbon::now(),
            ])->save();

            $this->record($held, DistributionAction::Reconcile, DistributionAttemptOutcome::Succeeded, 'The distributor holds this submission.', $startedAt);

            return $held->refresh();
        });
    }

    /**
     * Hold the row and confirm it is still awaiting an answer.
     *
     * `handle()` claims a delivery with a conditional `UPDATE` because reading
     * the status and then writing it lets two concurrent submitters both get
     * through. The two ways *out* of `SUBMITTED_UNCONFIRMED` need the same
     * protection and for the same reason: two operators answering the same
     * open question — one recording "it arrived, reference ABC", the other
     * "it never arrived" — would both pass an unguarded status check, both
     * write a decision, and leave the delivery holding whichever landed last
     * with two contradicting rows in a log that is supposed to be the record
     * of what was decided.
     *
     * A re-read under `lockForUpdate()` rather than a conditional `UPDATE`,
     * because the answer being written is not a single fixed status — it is
     * whichever of three outcomes the caller arrived at, and the row has to be
     * held while that is worked out. Same shape as
     * `RevokeExternalIdentifier`. Must be called inside a transaction.
     *
     * @throws DistributionException when somebody else answered first
     */
    private function claimUnknown(DistributionDelivery $delivery): DistributionDelivery
    {
        /** @var DistributionDelivery $fresh */
        $fresh = DistributionDelivery::query()
            ->whereKey($delivery->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $fresh->status->isUnknown()) {
            throw DistributionException::notReconcilable($fresh->status);
        }

        return $fresh;
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

        return DB::transaction(function () use ($delivery, $arrived, $externalReleaseId, $decidedBy, $note, $startedAt): DistributionDelivery {
            // Two people can be looking at the same stuck delivery, and they
            // can reach opposite conclusions. Whoever answers first is the
            // answer; the second is told the question is closed rather than
            // silently overwriting a recorded decision.
            $held = $this->claimUnknown($delivery);

            if ($arrived) {
                $held->forceFill([
                    'status' => DistributionDeliveryStatus::Submitted,
                    'external_release_id' => trim((string) $externalReleaseId),
                    // **The platform never received this value.** Recorded as
                    // such so nothing downstream — and nobody reading a screen
                    // — can mistake a person's report of a reference for the
                    // distributor having produced one.
                    'external_reference_source' => ExternalReferenceSource::ManualOperator,
                    'failure_reason' => null,
                    'submitted_at' => $held->submitted_at ?? Carbon::now(),
                ])->save();
            } else {
                $held->forceFill([
                    'status' => DistributionDeliveryStatus::Failed,
                    'failure_reason' => 'Resolved by a person: the distributor never received this submission.',
                ])->save();
            }

            $this->recordDecision($held, $decidedBy, $arrived, $note, $startedAt);

            return $held->refresh();
        });
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
