<?php

declare(strict_types=1);

namespace SaniTube\Releases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;

/**
 * A recording took a position in a release's running order.
 */
final class ReleaseTrackAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Release $release,
        public readonly Track $track,
        public readonly int $discNumber,
        public readonly int $trackNumber,
    ) {}
}
