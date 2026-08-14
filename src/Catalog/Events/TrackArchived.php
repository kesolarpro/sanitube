<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\Track;

/**
 * A recording was withdrawn from active use without being deleted.
 */
final class TrackArchived
{
    use Dispatchable;

    public function __construct(public readonly Track $track) {}
}
