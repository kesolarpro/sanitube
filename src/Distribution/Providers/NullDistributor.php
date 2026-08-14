<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Providers;

use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DeliveryStatus;

/**
 * The distributor a fresh install has: none.
 *
 * Keeps the Distribution module resolvable with no credentials configured, so
 * the rest of the platform can be built and tested long before any distributor
 * account exists.
 */
final readonly class NullDistributor implements Distributor
{
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
}
