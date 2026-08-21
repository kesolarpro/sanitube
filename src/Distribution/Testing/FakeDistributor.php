<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Testing;

use RuntimeException;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\Contracts\SupportsSubmissionLookup;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Distribution\Exceptions\SubmissionNotSent;
use SaniTube\Releases\Models\Release;

/**
 * A distributor that behaves however a test needs it to.
 *
 * Shipped rather than test-only, like the in-memory storage provider and the
 * fake analyser: it is what lets the whole distribution engine be exercised —
 * validate, prepare, submit, poll, take down — with no distributor account and
 * no network, which is the state this platform is actually in.
 *
 * It honours idempotency keys the way a distributor should: submitting twice
 * under the same key returns the original submission rather than creating a
 * second. That is the behaviour the engine is written against, and a fake that
 * did not honour it would let a duplicate-delivery bug through.
 */
final class FakeDistributor implements Distributor, SupportsSubmissionLookup
{
    /** @var array<string, DistributorSubmission> */
    private array $submissions = [];

    /** @var array<string, DeliveryStatus> */
    private array $states = [];

    /** @var list<string> */
    public array $rejectionReasons = [];

    public int $submitCalls = 0;

    private bool $available = true;

    private bool $outage = false;

    /** Whether this fake can be asked what it holds. Real ones often cannot. */
    /** Raised by submitRelease() to stand in for a read timeout. */
    private bool $swallowSubmission = false;

    /** Raised by submitRelease() to stand in for a refused connection. */
    private bool $refuseConnection = false;

    /**
     * Raised by prepareRelease() alone. Distinct from `down()`, which breaks
     * validation too and so never reaches preparation.
     */
    private bool $breakPreparation = false;

    public function __construct(
        private readonly string $name = 'fake',
        private readonly bool $sandbox = true,
    ) {}

    private ?string $preparationFailure = null;

    private ?string $outageMessage = null;

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function validateRelease(Release $release): DistributorValidation
    {
        $this->refuseIfDown();

        return new DistributorValidation(errors: $this->rejectionReasons);
    }

    public function prepareRelease(Release $release, string $idempotencyKey): DistributorSubmission
    {
        if ($this->breakPreparation) {
            throw new RuntimeException($this->preparationFailure ?? 'The upload was interrupted.');
        }

        $this->refuseIfDown();

        return new DistributorSubmission(
            accepted: true,
            externalReleaseId: 'prepared-'.substr($idempotencyKey, 0, 12),
            status: DeliveryStatus::Draft,
        );
    }

    public function submitRelease(Release $release, string $idempotencyKey): DistributorSubmission
    {
        if ($this->refuseConnection) {
            throw SubmissionNotSent::because('Connection refused.');
        }

        $this->refuseIfDown();
        $this->submitCalls++;

        if ($this->swallowSubmission) {
            // The package is recorded as arrived and the answer never comes
            // back — exactly the case DIST-001-H1 exists for. Recording it
            // *before* throwing is what makes a later lookup meaningful.
            $externalId = 'rel-'.substr($idempotencyKey, 0, 12);
            $this->states[$externalId] = DeliveryStatus::Submitted;
            $this->submissions[$idempotencyKey] = new DistributorSubmission(
                accepted: true,
                externalReleaseId: $externalId,
                status: DeliveryStatus::Submitted,
            );

            throw new RuntimeException('Read timed out waiting for the distributor.');
        }

        // A distributor that honours the key returns the original submission
        // rather than creating a second. The engine is written against this.
        if (isset($this->submissions[$idempotencyKey])) {
            return $this->submissions[$idempotencyKey];
        }

        if ($this->rejectionReasons !== []) {
            return $this->submissions[$idempotencyKey] = new DistributorSubmission(
                accepted: false,
                status: DeliveryStatus::Rejected,
                failureReason: implode('; ', $this->rejectionReasons),
            );
        }

        $externalId = 'rel-'.substr($idempotencyKey, 0, 12);
        $this->states[$externalId] = DeliveryStatus::Submitted;

        return $this->submissions[$idempotencyKey] = new DistributorSubmission(
            accepted: true,
            externalReleaseId: $externalId,
            status: DeliveryStatus::Submitted,
        );
    }

    public function deliveryStatus(string $externalReleaseId): DeliveryStatus
    {
        $this->refuseIfDown();

        return $this->states[$externalReleaseId] ?? DeliveryStatus::Failed;
    }

    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission
    {
        $this->refuseIfDown();

        $this->states[$externalReleaseId] = DeliveryStatus::TakedownRequested;

        return new DistributorSubmission(
            accepted: true,
            externalReleaseId: $externalReleaseId,
            status: DeliveryStatus::TakedownRequested,
        );
    }

    public function findSubmission(string $idempotencyKey): ?DistributorSubmission
    {
        // A transport failure while *asking* is not "holds nothing" — it is a
        // question that never completed, and the caller leaves the delivery
        // unknown rather than inventing an answer from it.
        $this->refuseIfDown();

        return $this->submissions[$idempotencyKey] ?? null;
    }

    // ------------------------------------------------------------- test seams

    /**
     * Accept the package and never answer — a read timeout after the request
     * landed. The one failure mode a retry must not be offered for.
     */
    public function swallowingSubmissions(): self
    {
        $this->swallowSubmission = true;

        return $this;
    }

    public function answeringSubmissions(): self
    {
        $this->swallowSubmission = false;

        return $this;
    }

    /**
     * Fail before the request leaves. Distinct from an outage: the adapter
     * *knows* nothing arrived, and says so.
     */
    public function refusingConnections(): self
    {
        $this->refuseConnection = true;

        return $this;
    }

    /**
     * Fail while uploading, and nowhere else.
     *
     * `down()` cannot express this: it breaks `validateRelease()` too, so
     * submission is refused before preparation is ever reached — which is
     * exactly how a test meant to cover the prepare path quietly covers the
     * validation path instead.
     */
    public function failingPreparation(?string $message = null): self
    {
        $this->breakPreparation = true;

        // A caller may choose the words. What a real client says when an
        // upload dies is not "the upload was interrupted" — it is the request
        // it was making, address and signature and all, and a test that can
        // only produce the polite sentence cannot check what happens to the
        // other one.
        $this->preparationFailure = $message;

        return $this;
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    /**
     * Behave like a distributor whose API is down. Distinct from unavailable,
     * which means "not configured here".
     */
    public function down(?string $message = null): self
    {
        $this->outage = true;

        // As with failingPreparation(): a caller may choose the words. A real
        // client's outage message is the request it was making, not a
        // sentence about availability.
        $this->outageMessage = $message;

        return $this;
    }

    public function recover(): self
    {
        $this->outage = false;

        return $this;
    }

    /**
     * @param  list<string>  $reasons
     */
    public function rejecting(array $reasons): self
    {
        $this->rejectionReasons = $reasons;

        return $this;
    }

    public function accepting(): self
    {
        $this->rejectionReasons = [];

        return $this;
    }

    /**
     * Move a delivery on, as a distributor does over days.
     */
    public function advance(string $externalReleaseId, DeliveryStatus $status): self
    {
        $this->states[$externalReleaseId] = $status;

        return $this;
    }

    private function refuseIfDown(): void
    {
        if ($this->outage) {
            throw new RuntimeException($this->outageMessage ?? 'The distributor API is unavailable.');
        }
    }
}
