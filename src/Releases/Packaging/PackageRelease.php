<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Models\TrackArtist;
use SaniTube\Catalog\Models\TrackContributor;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Exceptions\ReleasePackagingException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Models\ReleaseArtist;
use SaniTube\Releases\Models\ReleaseTrack;
use SaniTube\Releases\Services\ValidateRelease;

/**
 * Assembles the one description of a release that crosses to a distributor.
 *
 * **Validation first, and it is a refusal rather than a warning.** Packaging an
 * invalid release does not make it valid; it moves the rejection from somewhere
 * this platform controls to somewhere it does not, after a delivery has partly
 * happened. `ValidateRelease` already distinguishes errors from warnings, and
 * only errors block — a missing catalogue number is a label's business, a
 * missing ISRC is not this platform's to invent.
 *
 * **Identifiers are read active-only.** A revoked ISRC or UPC still exists as a
 * row, deliberately, so the history is readable. Presenting one to a
 * distributor as current is the specific mistake ARCH-002's `active_marker`
 * exists to make impossible, and this reads through the `active` scope so the
 * package cannot be the place it happens anyway.
 *
 * **A missing identifier is not an error.** SaniTube never invents an ISRC, so a
 * track may legitimately arrive without one and let the distributor assign it.
 * The nullable field is the honest answer; a fabricated one is not.
 *
 * **A track without a master is an error, not an omission.** Silently dropping
 * it would deliver a release missing a track, and nobody would find out until a
 * store published a four-track album as three.
 */
final readonly class PackageRelease
{
    public function __construct(private ValidateRelease $validator) {}

    /**
     * @throws ReleasePackagingException
     */
    public function handle(Release $release): ReleasePackage
    {
        $result = $this->validator->handle($release);

        if (! $result->isValid()) {
            throw ReleasePackagingException::notValid(array_map(
                static fn ($issue): string => $issue->code,
                $result->errors(),
            ));
        }

        // The three guards below are the packager's own preconditions, stated
        // where the packager needs them rather than trusted from a collaborator
        // two calls away.
        //
        // **Two of them are currently unreachable, and that is recorded rather
        // than hidden.** `ValidateRelease` already refuses a release with no
        // cover (COVER_REQUIRED) and one with no tracks (TRACKS_REQUIRED), so
        // the check above throws first. They stay because an unreachable guard
        // is what defence in depth looks like while the outer layer works, and
        // because without the cover check a validator change would turn a named
        // refusal into a TypeError. REL-003's report records the surviving
        // mutations against them rather than inventing tests to reach them.
        $cover = $release->coverAsset;

        if ($cover === null) {
            throw ReleasePackagingException::noCover();
        }

        /** @var list<Track> $tracks */
        $tracks = $release->tracks()->get()->all();

        if ($tracks === []) {
            throw ReleasePackagingException::noTracks();
        }

        $missing = [];

        foreach ($tracks as $track) {
            if ($track->masterAsset === null) {
                $missing[] = $track->uuid;
            }
        }

        if ($missing !== []) {
            throw ReleasePackagingException::trackWithoutMaster($missing);
        }

        return new ReleasePackage(
            uuid: $release->uuid,
            type: $release->type->value,
            title: $release->title,
            versionTitle: $release->version_title,
            upc: $this->identifier($release, ExternalIdentifierType::Upc),
            catalogueNumber: $release->catalogue_number,
            labelName: $release->label_name,
            releaseDate: $release->release_date?->toDateString(),
            originalReleaseDate: $release->original_release_date?->toDateString(),
            languageCode: $release->language_code,
            pLine: $release->p_line,
            cLine: $release->c_line,
            coverAssetUuid: $cover->uuid,
            artists: $this->releaseArtists($release),
            tracks: array_map(fn (Track $track): PackagedTrack => $this->track($release, $track), $tracks),
        );
    }

    private function track(Release $release, Track $track): PackagedTrack
    {
        // Through the typed pivot model rather than the magic `pivot`
        // property, which static analysis cannot see and which hands back an
        // untyped bag. ReleaseDetailQuery and ArtistDetailQuery already read
        // pivots this way.
        $pivot = $track->getRelation('pivot');
        $placement = $pivot instanceof ReleaseTrack ? $pivot : null;

        // Read into locals with explicit defaults rather than chaining a
        // nullsafe into a coalesce: a track reached through the relation always
        // carries its pivot, and writing as though it might not is how a
        // default silently becomes the value nobody notices.
        $discNumber = $placement instanceof ReleaseTrack ? $placement->disc_number : 1;
        $trackNumber = $placement instanceof ReleaseTrack ? $placement->track_number : 1;
        $isFocus = $placement instanceof ReleaseTrack && $placement->is_focus_track;

        return new PackagedTrack(
            uuid: $track->uuid,
            discNumber: $discNumber,
            trackNumber: $trackNumber,
            title: $track->title,
            versionTitle: $track->version_title,
            isrc: $this->identifier($track, ExternalIdentifierType::Isrc),
            languageCode: $track->language_code,
            isInstrumental: $track->is_instrumental,
            isExplicit: $track->is_explicit,
            durationMs: $track->duration_ms,
            // Falls back to the release's line. A store prints one per
            // recording, and a track that says nothing would be published with
            // no ownership line at all rather than the label's.
            pLine: $track->p_line ?? $release->p_line,
            genrePrimary: $track->genre_primary,
            genreSecondary: $track->genre_secondary,
            masterAssetUuid: (string) $track->masterAsset?->uuid,
            isFocusTrack: $isFocus,
            artists: $this->trackArtists($track),
            contributors: $this->trackContributors($track),
        );
    }

    /**
     * @return list<PackagedArtist>
     */
    private function releaseArtists(Release $release): array
    {
        $artists = [];

        /** @var Artist $artist */
        foreach ($release->artists()->get() as $artist) {
            $pivot = $artist->getRelation('pivot');
            $credit = $pivot instanceof ReleaseArtist ? $pivot : null;

            $artists[] = new PackagedArtist(
                name: $artist->name,
                role: $credit instanceof ReleaseArtist ? $credit->role->value : '',
                position: $credit instanceof ReleaseArtist ? $credit->position : 0,
                isni: $this->identifier($artist, ExternalIdentifierType::Isni),
            );
        }

        return $artists;
    }

    /**
     * @return list<PackagedArtist>
     */
    private function trackArtists(Track $track): array
    {
        $artists = [];

        /** @var Artist $artist */
        foreach ($track->artists()->get() as $artist) {
            $pivot = $artist->getRelation('pivot');
            $credit = $pivot instanceof TrackArtist ? $pivot : null;

            $artists[] = new PackagedArtist(
                name: $artist->name,
                role: $credit instanceof TrackArtist ? $credit->role->value : '',
                position: $credit instanceof TrackArtist ? $credit->position : 0,
                isni: $this->identifier($artist, ExternalIdentifierType::Isni),
            );
        }

        return $artists;
    }

    /**
     * @return list<PackagedContributor>
     */
    private function trackContributors(Track $track): array
    {
        $contributors = [];

        /** @var Contributor $contributor */
        foreach ($track->contributors()->get() as $contributor) {
            $pivot = $contributor->getRelation('pivot');
            $credit = $pivot instanceof TrackContributor ? $pivot : null;

            $contributors[] = new PackagedContributor(
                // The legal name, not the display name. A distributor passes
                // this to collecting societies, which match on the name a
                // person is registered under.
                name: $contributor->legal_name,
                role: $credit instanceof TrackContributor ? $credit->role->value : '',
                ipi: $this->identifier($contributor, ExternalIdentifierType::Ipi),
                isni: $this->identifier($contributor, ExternalIdentifierType::Isni),
            );
        }

        return $contributors;
    }

    /**
     * The entity's *active* identifier of a type, or null.
     *
     * The `active` scope is the whole point: a revoked identifier is still a
     * row, and handing one to a distributor as current is exactly what
     * ARCH-002's active marker exists to prevent.
     */
    private function identifier(object $entity, ExternalIdentifierType $type): ?string
    {
        if (! method_exists($entity, 'externalIdentifiers')) {
            return null;
        }

        /** @var ExternalIdentifier|null $identifier */
        $identifier = $entity->externalIdentifiers()
            ->active()
            ->where('type', $type)
            ->first();

        return $identifier?->value;
    }
}
