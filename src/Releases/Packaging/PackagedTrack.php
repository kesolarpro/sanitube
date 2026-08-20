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
 * `masterAssetUuid` is a uuid, never a path and never a location of any kind.
 * An adapter that needs the bytes asks the storage service for them; nothing in
 * a package tells anybody where a master lives.
 */
final readonly class PackagedTrack
{
    /**
     * @param  list<PackagedArtist>  $artists
     * @param  list<PackagedContributor>  $contributors
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
    ) {}
}
