<?php

declare(strict_types=1);

namespace SaniTube\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Assets\Models\Asset;

/**
 * These bytes match an asset the platform already holds. Recorded rather than rejected: a re-upload is a fact worth keeping.
 */
final class AssetDuplicateDetected
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
