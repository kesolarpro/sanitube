<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

/**
 * One track, as a distributor needs to see it.
 *
 * **`isrc` is nullable and that is not an oversight.** SaniTube never invents
 * an identifier, so a track may legitimately reach a distributor without one
 * and let the distributor assign it. What must never happen is a *revoked*
 * identifier appearing here as though it were current, which is why the
 * assembler reads only active rows.
 *
 * **`iswc` and `writers` describe the work, not the recording.** `isrc`
 * identifies this performance of it and `contributors` are the people who made
 * that performance; a composition has its own identifier and its own credits,
 * and a distributor passes those to collecting societies. Both are nullable and
 * empty for the same reason `isrc` is: SaniTube never invents an identifier,
 * and a track whose composition nobody has entered says so rather than guessing.
 *
 * `masterAssetUuid` is a uuid, never a path and never a location of any kind.
 * An adapter that needs the bytes asks the storage service for them; nothing in
 * a package tells anybody where a master lives.
 */
final readonly class PackagedTrack
{
    /**
     * @param  list<PackagedArtist>  $artists
     * @param  list<PackagedContributor>  $contributors
     * @param  list<PackagedWriter>  $writers
     */
    public function __construct(
        public string $uuid,
        public int $discNumber,
        public int $trackNumber,
        public string $title,
        public ?string $versionTitle,
        public ?string $isrc,
        public string $languageCode,
        public bool $isInstrumental,
        public bool $isExplicit,
        public ?int $durationMs,
        public ?string $pLine,
        public ?string $genrePrimary,
        public ?string $genreSecondary,
        public string $masterAssetUuid,
        public bool $isFocusTrack,
        public array $artists,
        public array $contributors,

        // The work, alongside the recording. A track's composition carries its
        // own identifier and its own people, and until DIST-008 none of it
        // crossed to a distributor — the catalogue held composers, lyricists
        // and publishers with their IPIs, and the package described only who
        // engineered the master.
        public ?string $iswc = null,

        /** @var list<PackagedWriter> */
        public array $writers = [],
    ) {}
}
