<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ui\Queries\TrackDetailQuery;

/**
 * One track.
 *
 * Bound by UUID — `Track::getRouteKeyName()` returns `uuid`, so the internal
 * key never appears in a URL and cannot be enumerated by counting upwards.
 */
final class TrackDetailController
{
    public function __invoke(Track $track, TrackDetailQuery $detail): Response
    {
        return Inertia::render('Catalog/Tracks/Show', [
            'track' => $detail->forTrack($track),
        ]);
    }
}
