<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Testing;

use RuntimeException;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
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
final class FakeDistributor implements Distributor
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

    public function __construct(
        private readonly string $name = 'fake',
        private readonly bool $sandbox = true,
    ) {}

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
        $this->refuseIfDown();

        return new DistributorSubmission(
            accepted: true,
            externalReleaseId: 'prepared-'.substr($idempotencyKey, 0, 12),
            status: DeliveryStatus::Draft,
        );
    }

    public function submitRelease(Release $release, string $idempotencyKey): DistributorSubmission
    {
        $this->refuseIfDown();
        $this->submitCalls++;

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

    // ------------------------------------------------------------- test seams

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    /**
     * Behave like a distributor whose API is down. Distinct from unavailable,
     * which means "not configured here".
     */
    public function down(): self
    {
        $this->outage = true;

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
            throw new RuntimeException('The distributor API is unavailable.');
        }
    }
}
