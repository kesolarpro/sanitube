<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use App\Models\User;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, Track $track, TrackDetailQuery $detail): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Catalog/Tracks/Show', [
            'track' => $detail->forTrack($track, $viewer),
        ]);
    }
}
