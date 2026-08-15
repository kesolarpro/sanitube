<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Manifest;

use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;

/**
 * Compares what a manifest claims against what the catalogue already holds.
 *
 * This exists because of one specific way a legacy import goes wrong. An
 * operator exports nine hundred rows from a previous distributor, some of
 * which carry ISRCs already in use here, and re-imports the lot. Nothing in
 * the import path assigns identifiers — promotion mints none — so no wrong
 * assignment happens. What happens instead is quieter and worse: the
 * recording lands as an ordinary candidate, somebody promotes it, and the
 * catalogue now holds two masters that both claim the same ISRC in the
 * operator's records while only one of them says so in the database.
 *
 * So the conflict is found at import time, recorded on the candidate, and the
 * candidate is held for review. The detector never resolves anything: it does
 * not pick a winner, does not attach the identifier, does not modify the
 * existing one. It reports.
 *
 * **An ISRC already in use is a conflict. A UPC already in use is not.** A UPC
 * identifies a release, and a manifest importing the twelve tracks of one
 * album will legitimately repeat the same UPC on all twelve rows, very often
 * for a release the catalogue already has. Treating that as a conflict would
 * send an entire album to review for being an album.
 */
final readonly class ManifestConflictDetector
{
    public const ISRC_ALREADY_ASSIGNED = 'ISRC_ALREADY_ASSIGNED';

    /**
     * @return array{conflicts: list<array<string, mixed>>, observations: list<array<string, mixed>>}
     */
    public function inspect(ManifestRow $row): array
    {
        $conflicts = [];
        $observations = [];

        if ($row->isrc !== null) {
            $holder = $this->trackHolding($row->isrc);

            if ($holder !== null) {
                $conflicts[] = [
                    'code' => self::ISRC_ALREADY_ASSIGNED,
                    'isrc' => $row->isrc,
                    'held_by_track' => $holder,
                    'message' => 'The manifest claims an ISRC that is already active on another track. '
                        .'It has not been assigned to this import, and will not be until somebody decides '
                        .'which recording it belongs to.',
                ];
            }
        }

        if ($row->upc !== null) {
            $release = $this->releaseHolding($row->upc);

            if ($release !== null) {
                // Recorded so a reviewer can see the release exists. Not a
                // conflict, and it changes nothing about the candidate.
                $observations[] = [
                    'code' => 'UPC_MATCHES_EXISTING_RELEASE',
                    'upc' => $row->upc,
                    'release' => $release,
                ];
            }
        }

        return ['conflicts' => $conflicts, 'observations' => $observations];
    }

    /**
     * The public uuid of the track actively holding this ISRC, if any.
     *
     * Only active identifiers count. A revoked ISRC is history — the whole
     * point of keeping revoked rows is that the value can legitimately be in
     * use again, or be reassigned deliberately.
     */
    private function trackHolding(string $isrc): ?string
    {
        /** @var int|null $trackId */
        $trackId = ExternalIdentifier::query()
            ->active()
            ->where('type', ExternalIdentifierType::Isrc->value)
            ->where('value', $isrc)
            ->where('identifiable_type', Track::class)
            ->value('identifiable_id');

        if ($trackId === null) {
            return null;
        }

        /** @var string|null $uuid */
        $uuid = Track::query()->whereKey($trackId)->value('uuid');

        return $uuid;
    }

    private function releaseHolding(string $upc): ?string
    {
        /** @var int|null $releaseId */
        $releaseId = ExternalIdentifier::query()
            ->active()
            ->where('type', ExternalIdentifierType::Upc->value)
            ->where('value', $upc)
            ->where('identifiable_type', Release::class)
            ->value('identifiable_id');

        if ($releaseId === null) {
            return null;
        }

        /** @var string|null $uuid */
        $uuid = Release::query()->whereKey($releaseId)->value('uuid');

        return $uuid;
    }
}
