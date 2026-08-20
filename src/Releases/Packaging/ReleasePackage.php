<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

/**
 * Everything a distributor needs about one release, and nothing else.
 *
 * **The point of this type is that it is the only thing that crosses the
 * boundary.** Before it, an adapter would have walked the Release aggregate
 * itself — reaching into tracks, credits, identifiers and assets — which means
 * every adapter reaches differently, every adapter has its own chance to read a
 * revoked identifier as current, and "what did we actually send" is answerable
 * only by re-running whatever that adapter happened to do.
 *
 * Assembled once from a release that has passed validation, immutable
 * afterwards.
 *
 * **No secret and no location is in here.** Assets are named by uuid; no disk,
 * no store, no path and no URL appears anywhere in the structure, and a test
 * asserts it by walking the whole thing rather than by checking the fields
 * somebody remembered. An adapter that needs bytes asks the storage service,
 * which is the only thing that knows where anything lives.
 *
 * **No money.** Not a price, not a rate, not a split. Rights *metadata* — ℗ and
 * © lines, contributor roles — is here because a distributor requires it to
 * publish; nothing here says what anybody earns.
 */
final readonly class ReleasePackage
{
    /**
     * @param  list<PackagedArtist>  $artists
     * @param  list<PackagedTrack>  $tracks
     */
    public function __construct(
        public string $uuid,
        public string $type,
        public string $title,
        public ?string $versionTitle,
        public ?string $upc,
        public ?string $catalogueNumber,
        public ?string $labelName,
        public ?string $releaseDate,
        public ?string $originalReleaseDate,
        public string $languageCode,
        public ?string $pLine,
        public ?string $cLine,
        public string $coverAssetUuid,
        public array $artists,
        public array $tracks,
    ) {}

    public function trackCount(): int
    {
        return count($this->tracks);
    }

    /**
     * The structure as plain data, for a manifest, a DDEX document or an audit
     * record.
     *
     * Deliberately hand-written rather than reflected: what leaves this
     * platform for a distributor should change only when somebody decides it
     * should, and a reflective serialiser would quietly export any property a
     * later ticket adds.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'title' => $this->title,
            'version_title' => $this->versionTitle,
            'upc' => $this->upc,
            'catalogue_number' => $this->catalogueNumber,
            'label_name' => $this->labelName,
            'release_date' => $this->releaseDate,
            'original_release_date' => $this->originalReleaseDate,
            'language_code' => $this->languageCode,
            'p_line' => $this->pLine,
            'c_line' => $this->cLine,
            'cover_asset_uuid' => $this->coverAssetUuid,
            'artists' => array_map(static fn (PackagedArtist $artist): array => [
                'name' => $artist->name,
                'role' => $artist->role,
                'position' => $artist->position,
                'isni' => $artist->isni,
            ], $this->artists),
            'tracks' => array_map(static fn (PackagedTrack $track): array => [
                'uuid' => $track->uuid,
                'disc_number' => $track->discNumber,
                'track_number' => $track->trackNumber,
                'title' => $track->title,
                'version_title' => $track->versionTitle,
                'isrc' => $track->isrc,
                'language_code' => $track->languageCode,
                'is_instrumental' => $track->isInstrumental,
                'is_explicit' => $track->isExplicit,
                'duration_ms' => $track->durationMs,
                'p_line' => $track->pLine,
                'genre_primary' => $track->genrePrimary,
                'genre_secondary' => $track->genreSecondary,
                'master_asset_uuid' => $track->masterAssetUuid,
                'is_focus_track' => $track->isFocusTrack,
                'artists' => array_map(static fn (PackagedArtist $artist): array => [
                    'name' => $artist->name,
                    'role' => $artist->role,
                    'position' => $artist->position,
                    'isni' => $artist->isni,
                ], $track->artists),
                'contributors' => array_map(static fn (PackagedContributor $contributor): array => [
                    'name' => $contributor->name,
                    'role' => $contributor->role,
                    'ipi' => $contributor->ipi,
                    'isni' => $contributor->isni,
                ], $track->contributors),
            ], $this->tracks),
        ];
    }
}
