<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Providers;

use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Releases\Packaging\ReleasePackage;

/**
 * The distributor a fresh install has: none.
 *
 * Keeps the Distribution module resolvable with no credentials configured, so
 * the rest of the platform can be built and tested long before any distributor
 * account exists.
 */
final readonly class NullDistributor implements Distributor
{
    private const REASON = 'No distributor is configured on this installation.';

    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function deliveryStatus(string $externalReleaseId): DeliveryStatus
    {
        return DeliveryStatus::Draft;
    }

    public function validateRelease(ReleasePackage $package): DistributorValidation
    {
        // Not "the release is fine" — nothing looked at it. An empty error
        // list would read as approval from a distributor that does not exist.
        return new DistributorValidation(errors: [self::REASON]);
    }

    public function prepareRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission
    {
        return DistributorSubmission::failed(self::REASON);
    }

    public function submitRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission
    {
        return DistributorSubmission::failed(self::REASON);
    }

    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission
    {
        return DistributorSubmission::failed(self::REASON);
    }

    /**
     * `null` rather than unsupported, and the distinction is real here: this
     * distributor has never sent anything anywhere, so it can say with
     * certainty that it holds nothing.
     */
}
