<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use Illuminate\Http\JsonResponse;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Queries\ReleasePackageQuery;

/**
 * Prepare the distribution package for one release.
 *
 * **A GET, because it creates nothing.** Assembling the package reads the
 * release aggregate and hands back a description; no delivery row, no status
 * change, no outbound call. A POST would suggest that asking the question left
 * a record somebody could later read as an intention — the same reason the
 * distributor preflight is a GET.
 *
 * **Answered on demand rather than drawn with the builder.** Packaging walks
 * every track, every credit and every identifier on the release. Doing that on
 * each render of a screen a label keeps open while editing would make a page
 * load expensive for an answer most renders do not need.
 *
 * **Behind the catalogue role, not merely behind being signed in.** The package
 * carries contributors' *legal* names — the ones collecting societies match on
 * — which no other release screen shows, and it is the description of what
 * would be handed to a third party. A read-only member has no business with
 * either.
 */
final class ReleasePackageController
{
    public function __invoke(Release $release, ReleasePackageQuery $packages): JsonResponse
    {
        return response()->json($packages->forRelease($release));
    }
}
