<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\StudioOverviewQuery;

/**
 * What the studio can do, and what it has done.
 *
 * Nothing here reaches a provider over the network. `isAvailable()` is asked
 * of the configured provider object; a page load that probed a vendor would
 * make the studio as slow and as unreliable as the slowest supplier, which is
 * the rule UI-002's review settled for every provider surface.
 */
final class OverviewController
{
    public function __invoke(Request $request, StudioOverviewQuery $overview): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Studio/Index', ['studio' => $overview->get($viewer)]);
    }
}
