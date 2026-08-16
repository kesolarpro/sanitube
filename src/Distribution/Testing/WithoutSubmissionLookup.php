<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Testing;

use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\Contracts\SupportsSubmissionLookup;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Releases\Models\Release;

/**
 * A distributor whose provider cannot be asked what it holds.
 *
 * The shape of a real adapter for a provider with no lookup endpoint, which is
 * most of them: it delivers, it reports status, and it has no way to answer
 * "do you already have submission `abc`?". Written as a decorator rather than
 * a flag on {@see FakeDistributor} because that is the whole point of the
 * capability — **not implementing {@see SupportsSubmissionLookup}
 * is the answer**, checked by the service before any call is made. A boolean
 * that made the same object sometimes searchable would put the distinction
 * back inside a method body, where it was.
 */
final readonly class WithoutSubmissionLookup implements Distributor
{
    public function __construct(private Distributor $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function isSandbox(): bool
    {
        return $this->inner->isSandbox();
    }

    public function deliveryStatus(string $externalReleaseId): DeliveryStatus
    {
        return $this->inner->deliveryStatus($externalReleaseId);
    }

    public function validateRelease(Release $release): DistributorValidation
    {
        return $this->inner->validateRelease($release);
    }

    public function prepareRelease(Release $release, string $idempotencyKey): DistributorSubmission
    {
        return $this->inner->prepareRelease($release, $idempotencyKey);
    }

    public function submitRelease(Release $release, string $idempotencyKey): DistributorSubmission
    {
        return $this->inner->submitRelease($release, $idempotencyKey);
    }

    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission
    {
        return $this->inner->requestTakedown($externalReleaseId, $idempotencyKey);
    }
}
