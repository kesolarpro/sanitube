<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Illuminate\Http\RedirectResponse;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Exceptions\TrackCreditException;
use SaniTube\Catalog\Exceptions\TrackNotReadyException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\SetTrackCredits;
use SaniTube\Ui\Http\Requests\Catalog\TrackCreditsRequest;

/**
 * The two things a person can do to a track.
 *
 * CAT-002. Until this existed the catalogue was read-only from a browser, and
 * the consequence was not that a screen was missing a button: promotion
 * creates a DRAFT track, I3 refuses READY without a primary artist, and
 * nothing anywhere gave a person a way to supply one. A label who imported a
 * library could promote a candidate and then stop, with every release built
 * from those tracks failing validation on an issue whose fix was unreachable.
 *
 * **`status` is never written here.** Readiness is *earned* by passing I3, not
 * assigned: `markReady()` re-runs the invariants and throws if they do not
 * hold, so a controller that set `status = READY` would be skipping them while
 * looking like it did the same thing. Same rule the release actions follow,
 * and for the same reason.
 *
 * Refusals come back as machine-readable codes through `withErrors`. The
 * domain's English is written for a developer reading a log.
 */
final class TrackActionController
{
    public function setCredits(
        TrackCreditsRequest $request,
        Track $track,
        SetTrackCredits $credits,
    ): RedirectResponse {
        $resolved = [];

        foreach ($request->credits() as $credit) {
            $artist = Artist::query()->where('uuid', $credit['uuid'])->first();

            if (! $artist instanceof Artist) {
                return $this->refused('UNKNOWN_ARTIST');
            }

            $resolved[] = ['artist' => $artist, 'role' => TrackArtistRole::from($credit['role'])];
        }

        try {
            $credits->handle($track, $resolved);
        } catch (TrackCreditException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'track.credits_saved');
    }

    /**
     * Mark ready — earned, never assigned.
     */
    public function markReady(Track $track): RedirectResponse
    {
        try {
            $track->markReady();
        } catch (TrackNotReadyException) {
            // The problems are already in the payload the screen renders, from
            // the same `readinessProblems()` this throws on. Repeating them in
            // a flash message would be a second copy that can disagree.
            return $this->refused('TRACK_NOT_READY');
        }

        return back()->with('status', 'track.ready');
    }

    private function refused(string $code): RedirectResponse
    {
        return back()->withErrors(['track' => $code]);
    }
}
