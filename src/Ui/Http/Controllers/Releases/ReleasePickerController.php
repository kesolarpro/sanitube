<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use Illuminate\Http\JsonResponse;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Http\Requests\Releases\ReleasePickerRequest;
use SaniTube\Ui\Queries\ReleasePickerQuery;

/**
 * The builder's three pickers, as JSON.
 *
 * The only place in the interface that answers with JSON rather than an
 * Inertia page, and for a reason worth stating: a picker is typed into. Sending
 * a full page render per keystroke is how a search box becomes unusable, and
 * shipping the whole catalogue to the browser up front so it can be filtered
 * there is the other way to get the same result.
 *
 * These are session-authenticated reads on the same routes as the rest of the
 * interface — not the token API. They are behind the write role because they
 * exist to serve editing forms, and a reader who cannot add a track has no use
 * for the list of tracks they could add.
 */
final class ReleasePickerController
{
    public function tracks(ReleasePickerRequest $request, Release $release, ReleasePickerQuery $picker): JsonResponse
    {
        return response()->json(['rows' => $picker->tracks($release, $request->term())]);
    }

    public function artists(ReleasePickerRequest $request, ReleasePickerQuery $picker): JsonResponse
    {
        return response()->json(['rows' => $picker->artists($request->term())]);
    }

    public function artwork(ReleasePickerRequest $request, ReleasePickerQuery $picker): JsonResponse
    {
        return response()->json(['rows' => $picker->artwork($request->term())]);
    }
}
