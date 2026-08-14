<?php

declare(strict_types=1);

namespace SaniTube\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Assets\Models\Asset;

/**
 * Bytes are on a disk and the record describing them is now frozen.
 */
final class AssetStored
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
