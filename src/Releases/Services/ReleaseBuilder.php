<?php

declare(strict_types=1);

namespace SaniTube\Releases\Services;

use Illuminate\Support\Facades\DB;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Exceptions\ReleaseBuilderException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\ReleaseMutationPolicy;

/**
 * Assembling a release, one deliberate change at a time.
 *
 * ARCH-002 modelled what a release *is*; this is how one gets built. Every
 * method here goes through {@see ReleaseMutationPolicy} first, so "can this
 * still be changed?" is answered in one place rather than re-derived at nine
 * call sites — and so a release that a distributor already has cannot be
 * quietly edited into disagreeing with what stores are serving.
 *
 * Nothing here marks a release ready. Validation and readiness are separate
 * because a label wants to *see* what is missing long before it is ready to
 * commit, and a validator that only runs at the last moment is a validator
 * nobody uses.
 */
final readonly class ReleaseBuilder
{
    public function createDraft(
        string $title,
        ReleaseType $type = ReleaseType::Single,
        ?string $languageCode = null,
        ?string $versionTitle = null,
    ): Release {
        return Release::query()->create([
            'title' => $title,
            'version_title' => $versionTitle,
            'type' => $type,
            // `und` rather than a guess. A wrong language reaches DSPs.
            'language_code' => $languageCode ?? ContentLanguage::UNKNOWN,
            'status' => ReleaseStatus::Draft,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Release $release, array $attributes): Release
    {
        $this->guard($release);

        $allowed = [
            'title', 'version_title', 'label_name', 'catalogue_number',
            'release_date', 'original_release_date', 'language_code', 'p_line', 'c_line', 'type',
        ];

        $changes = array_intersect_key($attributes, array_flip($allowed));

        if ($changes !== []) {
            $release->forceFill($changes)->save();
        }

        return $release->refresh();
    }

    /**
     * Add a track to the end of a disc.
     *
     * The number is derived rather than accepted: a caller that picks its own
     * would eventually pick one that already exists, and a tracklist with two
     * number 4s is a delivery a store rejects.
     */
    public function addTrack(Release $release, Track $track, int $disc = 1, bool $isFocusTrack = false): Release
    {
        $this->guard($release);

        if ($release->tracks()->whereKey($track->id)->exists()) {
            throw ReleaseBuilderException::trackAlreadyOnRelease($track->uuid);
        }

        $next = (int) $release->trackEntries()->where('disc_number', $disc)->max('track_number') + 1;

        $release->tracks()->attach($track->id, [
            'disc_number' => $disc,
            'track_number' => $next,
            'is_focus_track' => $isFocusTrack,
        ]);

        return $release->refresh();
    }

    /**
     * Remove a track and close the gap it leaves.
     *
     * Renumbering immediately is the whole reason this is a service rather
     * than a `detach()`. A tracklist that runs 1, 2, 4 is rejected by stores,
     * and leaving the gap until someone happens to reorder means the release
     * is invalid in a way nobody looked at.
     */
    public function removeTrack(Release $release, Track $track): Release
    {
        $this->guard($release);

        if (! $release->tracks()->whereKey($track->id)->exists()) {
            throw ReleaseBuilderException::trackNotOnRelease($track->uuid);
        }

        return DB::transaction(function () use ($release, $track): Release {
            $release->tracks()->detach($track->id);

            return $this->renumber($release);
        });
    }

    /**
     * Set the running order from an explicit list of track uuids.
     *
     * The list is the order; positions are assigned from it. Accepting
     * caller-supplied numbers would make it possible to submit a gapped or
     * duplicated tracklist, which is exactly what this method exists to
     * prevent.
     *
     * @param  list<string>  $trackUuids  in the order they should play
     */
    public function reorder(Release $release, array $trackUuids, int $disc = 1): Release
    {
        $this->guard($release);

        $onDisc = $release->trackEntries()->where('disc_number', $disc)->get();
        $byTrackId = Track::query()
            ->whereIn('uuid', $trackUuids)
            ->pluck('id', 'uuid');

        return DB::transaction(function () use ($release, $trackUuids, $disc, $onDisc, $byTrackId): Release {
            $order = [];

            foreach ($trackUuids as $uuid) {
                $trackId = $byTrackId[$uuid] ?? null;

                // Naming a track that is not on this disc is a caller mistake,
                // and silently adding it would be a surprising way to learn
                // that reorder can also insert.
                if ($trackId !== null && $onDisc->firstWhere('track_id', $trackId) !== null) {
                    $order[] = (int) $trackId;
                }
            }

            // Anything the caller left out keeps its relative order and
            // follows. Dropping it would make reorder a destructive operation
            // that looks like a cosmetic one.
            foreach ($onDisc->sortBy('track_number') as $pivot) {
                if (! in_array((int) $pivot->track_id, $order, true)) {
                    $order[] = (int) $pivot->track_id;
                }
            }

            $this->applyOrder($release, $disc, $order);

            return $release->refresh();
        });
    }

    /**
     * Replace the release's artist credits.
     *
     * @param  list<array{artist: Artist, role?: ReleaseArtistRole}>  $credits
     */
    public function setArtists(Release $release, array $credits): Release
    {
        $this->guard($release);

        $hasPrimary = false;
        $attach = [];
        $position = 0;

        foreach ($credits as $credit) {
            $role = $credit['role'] ?? ReleaseArtistRole::Primary;
            $hasPrimary = $hasPrimary || $role === ReleaseArtistRole::Primary;
            $attach[$credit['artist']->id] = ['role' => $role->value, 'position' => ++$position];
        }

        if (! $hasPrimary) {
            throw ReleaseBuilderException::primaryArtistRequired();
        }

        $release->artists()->sync($attach);

        return $release->refresh();
    }

    /**
     * Attach the cover.
     *
     * Checked here rather than only at readiness, because a cover that turns
     * out to be an unverified audio file is a mistake worth catching when it
     * is made — not at the end, when somebody is trying to deliver.
     */
    public function setCover(Release $release, Asset $cover): Release
    {
        $this->guard($release);

        if ($cover->kind !== AssetKind::Artwork) {
            throw ReleaseBuilderException::coverNotUsable(sprintf(
                'A cover must be an ARTWORK asset; [%s] is %s.',
                $cover->uuid,
                $cover->kind->value,
            ));
        }

        if ($cover->status !== AssetStatus::Verified) {
            throw ReleaseBuilderException::coverNotUsable(sprintf(
                'The cover asset has not been verified — it is %s. Delivering artwork nobody has '
                    .'confirmed is how a store receives a corrupt image.',
                $cover->status->value,
            ));
        }

        $release->forceFill(['cover_asset_id' => $cover->id])->save();

        return $release->refresh();
    }

    /**
     * Retract a READY release so it can be edited again.
     */
    public function reopen(Release $release): Release
    {
        if (! ReleaseMutationPolicy::allowsReopen($release->status)) {
            throw ReleaseBuilderException::notReopenable($release->status);
        }

        $release->forceFill(['status' => ReleaseStatus::Draft])->save();

        return $release->refresh();
    }

    /**
     * Close every gap and start each disc at 1.
     */
    private function renumber(Release $release): Release
    {
        foreach ($release->trackEntries()->get()->groupBy('disc_number') as $disc => $pivots) {
            $this->applyOrder(
                $release,
                (int) $disc,
                $pivots->sortBy('track_number')->pluck('track_id')->map(intval(...))->all(),
            );
        }

        return $release->refresh();
    }

    /**
     * Write a disc's running order, in two passes.
     *
     * `release_tracks` carries a unique index on
     * `(release_id, disc_number, track_number)` — ARCH-002's guarantee that no
     * two tracks share a position — and renumbering row by row collides with
     * it the moment a track moves into a slot another track has not yet left.
     *
     * So every row is first parked above the occupied range, then written to
     * its final number. Two passes rather than one, because the alternative is
     * to drop the constraint that makes the ordering trustworthy in the first
     * place.
     *
     * @param  list<int>  $trackIds  in the order they should play
     */
    private function applyOrder(Release $release, int $disc, array $trackIds): void
    {
        $parking = (int) $release->trackEntries()->max('track_number') + 1;

        foreach ($trackIds as $offset => $trackId) {
            $release->tracks()->updateExistingPivot($trackId, [
                'disc_number' => $disc,
                'track_number' => $parking + $offset,
            ]);
        }

        foreach ($trackIds as $offset => $trackId) {
            $release->tracks()->updateExistingPivot($trackId, [
                'disc_number' => $disc,
                'track_number' => $offset + 1,
            ]);
        }
    }

    private function guard(Release $release): void
    {
        if (! ReleaseMutationPolicy::allowsStructuralChange($release->status)) {
            throw ReleaseBuilderException::notEditable($release->status);
        }
    }
}
