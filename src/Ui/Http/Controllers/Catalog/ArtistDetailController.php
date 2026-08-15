<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Artists\Models\Artist;
use SaniTube\Ui\Queries\ArtistDetailQuery;

/**
 * One artist, bound by UUID so the internal key never appears in a URL.
 */
final class ArtistDetailController
{
    public function __invoke(Artist $artist, ArtistDetailQuery $detail): Response
    {
        return Inertia::render('Catalog/Artists/Show', [
            'artist' => $detail->forArtist($artist),
        ]);
    }
}
