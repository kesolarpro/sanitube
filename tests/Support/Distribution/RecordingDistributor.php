<?php

declare(strict_types=1);

namespace Tests\Support\Distribution;

use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Releases\Packaging\ReleasePackage;

/**
 * A distributor that keeps what it was handed.
 *
 * A decorator around the platform's own fake rather than a fresh
 * implementation, so the idempotency and outcome behaviour the rest of the
 * suite is written against stays exactly that behaviour. The same shape
 * `WithoutSubmissionLookup` uses.
 */
final class RecordingDistributor implements Distributor
{
    public ?ReleasePackage $prepared = null;

    public ?ReleasePackage $submitted = null;

    public function __construct(private readonly FakeDistributor $inner) {}

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

    public function validateRelease(ReleasePackage $package): DistributorValidation
    {
        return $this->inner->validateRelease($package);
    }

    public function prepareRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission
    {
        $this->prepared = $package;

        return $this->inner->prepareRelease($package, $idempotencyKey);
    }

    public function submitRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission
    {
        $this->submitted = $package;

        return $this->inner->submitRelease($package, $idempotencyKey);
    }

    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission
    {
        return $this->inner->requestTakedown($externalReleaseId, $idempotencyKey);
    }
}
