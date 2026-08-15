<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributionIdempotencyKey;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Exceptions\DistributionException;
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

        if ($this->wasUnreachable($verdict->errors, $distributor->name())) {
            // An outage is not a rejection. The package may be perfectly
            // acceptable; nobody could ask. FAILED is retryable and keeps the
            // same idempotency key, where REJECTED would read as a verdict the
            // distributor never gave.
            return $this->fail($delivery, implode('; ', $verdict->errors), hrtime(true));
        }

        if (! $verdict->isValid()) {
            $this->record($delivery, DistributionAction::Validate, DistributionAttemptOutcome::Rejected, implode('; ', $verdict->errors));

            throw DistributionException::rejectedByDistributor($verdict->errors);
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
            $distributor->prepareRelease($release, $delivery->idempotency_key);
            $submission = $distributor->submitRelease($release, $delivery->idempotency_key);
        } catch (Throwable $exception) {
            // An outage leaves the delivery FAILED rather than stuck in
            // SUBMITTING — a row that is permanently mid-flight is a row
            // nobody retries and nobody cleans up. FAILED is submittable
            // again, and the same key makes the retry recognisable.
            return $this->fail($delivery, $exception->getMessage(), $startedAt);
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

    /**
     * @param  list<string>  $errors
     */
    private function wasUnreachable(array $errors, string $provider): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, sprintf('The [%s] distributor could not be reached', $provider))) {
                return true;
            }
        }

        return false;
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
