<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Queries\ReleaseDistributionQuery;

/**
 * Where this release could go.
 *
 * Read-only. The preflight that asks a distributor for its opinion is a
 * separate, explicit action — a page that validated on render would make every
 * refresh an outbound call, and a distributor's outage would take the screen
 * down instead of appearing on it.
 */
final class ReleaseDistributionController
{
    public function __invoke(
        Request $request,
        Release $release,
        ReleaseDistributionQuery $destinations,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Distribution/Send', [
            'send' => $destinations->forRelease($release, $viewer),
        ]);
    }
}
