<?php

declare(strict_types=1);

namespace SaniTube\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Assets\Models\Asset;

/**
 * The stored bytes were re-read and matched their recorded checksum.
 */
final class AssetVerified
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
