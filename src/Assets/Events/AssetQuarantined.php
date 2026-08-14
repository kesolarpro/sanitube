<?php

declare(strict_types=1);

namespace SaniTube\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Assets\Models\Asset;

/**
 * The asset failed a check and must not be used until reviewed.
 */
final class AssetQuarantined
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
