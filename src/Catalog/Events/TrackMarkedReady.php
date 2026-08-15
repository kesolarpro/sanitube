<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\Track;

/**
 * A recording passed its readiness checks and may now appear on a release.
 */
final class TrackMarkedReady
{
    use Dispatchable;

    public function __construct(public readonly Track $track) {}
}
