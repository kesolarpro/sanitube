<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Services;

use Illuminate\Support\Facades\DB;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Exceptions\TrackCreditException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Models\TrackArtist;

/**
 * Who made this recording.
 *
 * CAT-002. Promotion creates a track in DRAFT with no credits, and I3 refuses
 * READY without a primary artist — both correct, and together they left a
 * track that could be created and never finished. This is the missing step.
 *
 * **Credit, not entitlement.** A name in `track_artist` says who performed on
 * a recording, which is what a store displays and what a distributor's
 * metadata requires. It says nothing about who is owed anything; SaniTube
 * holds no such record and this does not become one.
 *
 * The list replaces rather than patches, for the reason `ReleaseBuilder`
 * gives about release credits: the order is part of the credit — "A & B" is
 * not "B & A" — so a caller sends the list it wants and positions come from
 * it.
 *
 * `track_artist` is unique on `(track, artist, role)`, which is deliberate:
 * the same person can legitimately be a PRIMARY and a REMIXER on one
 * recording. So credits are written one row per pair rather than through
 * `sync()`, which is keyed by artist and would silently keep only the last
 * role given.
 */
final readonly class SetTrackCredits
{
    /**
     * @param  list<array{artist: Artist, role?: TrackArtistRole}>  $credits
     *
     * @throws TrackCreditException
     */
    public function handle(Track $track, array $credits): Track
    {
        $this->guard($track);

        $rows = [];
        $seen = [];
        $hasPrimary = false;
        $position = 0;

        foreach ($credits as $credit) {
            $role = $credit['role'] ?? TrackArtistRole::Primary;
            $key = $credit['artist']->getKey().'@'.$role->value;

            // The same person in the same role twice is one credit. Left in,
            // it is a unique-constraint violation reported as a 500 for what
            // is really a duplicated line in a form.
            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $hasPrimary = $hasPrimary || $role === TrackArtistRole::Primary;

            $rows[] = [
                'track_id' => $track->getKey(),
                'artist_id' => $credit['artist']->getKey(),
                'role' => $role->value,
                'position' => ++$position,
            ];
        }

        if (! $hasPrimary) {
            throw TrackCreditException::primaryArtistRequired();
        }

        DB::transaction(function () use ($track, $rows): void {
            TrackArtist::query()->where('track_id', $track->getKey())->delete();

            foreach ($rows as $row) {
                TrackArtist::query()->create($row);
            }
        });

        return $track->refresh();
    }

    /**
     * A committed recording keeps the credits it was delivered with.
     *
     * RELEASED and ARCHIVED are outside SaniTube's control in the way a
     * DRAFT or READY track is not: a store is serving the recording under
     * the metadata it was given, and editing the local row would only make
     * the two disagree without changing anything a listener sees.
     *
     * @throws TrackCreditException
     */
    private function guard(Track $track): void
    {
        if (in_array($track->status, [TrackStatus::Released, TrackStatus::Archived], true)) {
            throw TrackCreditException::notCreditable($track->status);
        }
    }
}
