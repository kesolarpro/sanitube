<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Queries\ReleaseDetailQuery;

/**
 * The release builder. Bound by UUID.
 *
 * Read-only in itself — every mutation is a named action on
 * {@see ReleaseActionController}, because a release is assembled by a sequence
 * of decisions and each of them can be refused for its own reason.
 */
final class ReleaseBuilderController
{
    public function __invoke(Request $request, Release $release, ReleaseDetailQuery $detail): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Releases/Show', [
            'release' => $detail->forRelease($release, $viewer),
        ]);
    }
}
