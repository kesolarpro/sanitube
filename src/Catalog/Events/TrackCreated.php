<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\Track;

/**
 * A recording entered the catalogue, in whatever state.
 */
final class TrackCreated
{
    use Dispatchable;

    public function __construct(public readonly Track $track) {}
}
