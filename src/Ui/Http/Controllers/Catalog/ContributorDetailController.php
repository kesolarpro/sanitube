<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Ui\Queries\ContributorDetailQuery;

/**
 * Bound by UUID, so the internal key never appears in a URL and the catalogue
 * cannot be enumerated by counting upwards.
 */
final class ContributorDetailController
{
    public function __invoke(Contributor $contributor, ContributorDetailQuery $detail): Response
    {
        return Inertia::render('Catalog/Contributors/Show', [
            'contributor' => $detail->forContributor($contributor),
        ]);
    }
}
