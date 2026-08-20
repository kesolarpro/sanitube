<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Services;

use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Releases\Models\Release;

/**
 * The identifiers every delivery needs, whoever it is going to.
 *
 * Lifted out of {@see ValidateDelivery} unchanged, for one product reason: a
 * label could not find out about these until a distributor was configured.
 * `ValidateDelivery` is only ever asked about a *destination*, and on an
 * installation with no distributor — the shipped default, and the state every
 * installation starts in — the release screen showed a release with no UPC and
 * no ISRCs as fully valid, marked it READY, and said nothing. The first news
 * would have arrived from a store.
 *
 * These requirements have nothing to do with which distributor is chosen. A
 * store identifies a product by its UPC and a recording by its ISRC; every one
 * of them does. So they are answerable without a destination, and REL-004 asks
 * them from the release screen.
 *
 * **No identifier is ever minted here.** A missing ISRC is an error, full stop.
 * Assigning one automatically to a recording that may already have been
 * distributed under a different ISRC is the one mistake that cannot be taken
 * back once a store has seen it — and the platform has no way to know whether a
 * legacy import already carries one.
 */
final readonly class ValidateReleaseIdentifiers
{
    /**
     * @return list<ValidationIssue>
     */
    public function handle(Release $release): array
    {
        $errors = [];

        if (! $this->hasIdentifier($release, ExternalIdentifierType::Upc)
            && ! $this->hasIdentifier($release, ExternalIdentifierType::Ean)) {
            $errors[] = ValidationIssue::error(
                'NO_PRODUCT_IDENTIFIER',
                'identifiers',
                'The release has no UPC or EAN. Stores identify a product by it, and SaniTube does not mint one.',
            );
        }

        foreach ($release->tracks()->get() as $track) {
            /** @var Track $track */
            if ($track->externalIdentifiers()
                ->where('type', ExternalIdentifierType::Isrc->value)
                ->whereNotNull('active_marker')
                ->doesntExist()) {
                $errors[] = ValidationIssue::error(
                    'TRACK_NO_ISRC',
                    'tracks.'.$track->uuid,
                    sprintf(
                        'Track "%s" has no active ISRC. Assign one deliberately — SaniTube never mints '
                            .'an ISRC, because a recording that was already distributed elsewhere '
                            .'already has one.',
                        $track->title,
                    ),
                    ['title' => $track->title],
                );
            }
        }

        return $errors;
    }

    /**
     * Active only. A revoked UPC is still a row, deliberately, so the history
     * is readable — but a delivery identified by one is not identified.
     */
    private function hasIdentifier(Release $release, ExternalIdentifierType $type): bool
    {
        return $release->externalIdentifiers()
            ->where('type', $type->value)
            ->whereNotNull('active_marker')
            ->exists();
    }
}
