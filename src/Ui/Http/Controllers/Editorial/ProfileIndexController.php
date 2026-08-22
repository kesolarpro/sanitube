<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Editorial;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\EditorialProfileIndexQuery;

/**
 * The imprints this installation writes in the manner of.
 *
 * PROD-002. A read, behind the same role as the production writes rather than
 * open to everybody: an editorial profile is the label's own policy — the
 * words it avoids, the guidance it gives — and that is closer to configuration
 * than to catalogue.
 */
final class ProfileIndexController
{
    public function __invoke(EditorialProfileIndexQuery $profiles): Response
    {
        return Inertia::render('Editorial/Index', [
            'profiles' => $profiles->overview(),
        ]);
    }
}
