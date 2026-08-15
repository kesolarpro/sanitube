<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Ui\Queries\CompositionDetailQuery;

/**
 * Bound by UUID, so the internal key never appears in a URL and the catalogue
 * cannot be enumerated by counting upwards.
 */
final class CompositionDetailController
{
    public function __invoke(Composition $composition, CompositionDetailQuery $detail): Response
    {
        return Inertia::render('Catalog/Compositions/Show', [
            'composition' => $detail->forComposition($composition),
        ]);
    }
}
