<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Services;

use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\ReleaseValidationResult;
use SaniTube\Releases\Services\ValidateRelease;
use Throwable;

/**
 * Everything that must be true before a release is handed over.
 *
 * Three layers, and they are genuinely different questions:
 *
 *   1. **REL-001's validation** — is the package internally coherent?
 *   2. **Identifiers** — does every track carry an ISRC, and the release a UPC?
 *   3. **The distributor's own opinion** — will *this* distributor take it?
 *
 * The third is asked last and separately because every distributor's rules are
 * stricter and different: a release can be perfectly valid and still be refused
 * for a territory restriction or a genre code one store does not recognise.
 *
 * **No identifier is ever minted here.** A missing ISRC is a validation error,
 * full stop. Assigning one automatically to a recording that may already have
 * been distributed under a different ISRC is the one mistake that cannot be
 * taken back once a store has seen it — and the platform has no way to know
 * whether a legacy import already carries one. Assignment is a separate,
 * deliberate service in a later ticket.
 */
final readonly class ValidateDelivery
{
    public function __construct(private ValidateRelease $releases) {}

    public function handle(Release $release, Distributor $distributor): ReleaseValidationResult
    {
        $internal = $this->releases->handle($release);

        $errors = array_merge($internal->errors, $this->identifierErrors($release));
        $warnings = $internal->warnings;

        if (! $distributor->isAvailable()) {
            // Not configured is not approval. Skipping the distributor's
            // verdict and returning "valid" would tell a label its release is
            // ready to deliver on an installation that cannot deliver
            // anything.
            $errors[] = sprintf(
                'The [%s] distributor is not configured on this installation, so no delivery '
                    .'verdict is available.',
                $distributor->name(),
            );
        }

        if ($distributor->isAvailable()) {
            try {
                $verdict = $distributor->validateRelease($release);
                $errors = array_merge($errors, $verdict->errors);
                $warnings = array_merge($warnings, $verdict->warnings);
            } catch (Throwable $exception) {
                // Unreachable is an *error*, not a pass. "We could not ask"
                // and "it said yes" are opposite answers, and treating an
                // outage as approval is how a package goes out unchecked.
                // It never escapes as an exception either: a distributor being
                // down must not break the screen a label is looking at.
                $errors[] = sprintf(
                    'The [%s] distributor could not be reached, so its verdict is unknown: %s',
                    $distributor->name(),
                    $exception->getMessage(),
                );
            }
        }

        if ($distributor->isSandbox()) {
            // Not an error — a sandbox delivery is a legitimate rehearsal —
            // but submitting a real release to a sandbox is exactly the kind
            // of mistake that must be visible rather than discovered later.
            $warnings[] = sprintf(
                'The [%s] distributor is in sandbox mode. Nothing submitted will reach stores.',
                $distributor->name(),
            );
        }

        return new ReleaseValidationResult(
            errors: array_values(array_unique($errors)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @return list<string>
     */
    private function identifierErrors(Release $release): array
    {
        $errors = [];

        if (! $this->hasIdentifier($release, ExternalIdentifierType::Upc)
            && ! $this->hasIdentifier($release, ExternalIdentifierType::Ean)) {
            $errors[] = 'The release has no UPC or EAN. Stores identify a product by it, and '
                .'SaniTube does not mint one.';
        }

        foreach ($release->tracks()->get() as $track) {
            /** @var Track $track */
            if ($track->externalIdentifiers()
                ->where('type', ExternalIdentifierType::Isrc->value)
                ->whereNotNull('active_marker')
                ->doesntExist()) {
                $errors[] = sprintf(
                    'Track "%s" has no active ISRC. Assign one deliberately — SaniTube never mints '
                        .'an ISRC, because a recording that was already distributed elsewhere '
                        .'already has one.',
                    $track->title,
                );
            }
        }

        return $errors;
    }

    private function hasIdentifier(Release $release, ExternalIdentifierType $type): bool
    {
        return $release->externalIdentifiers()
            ->where('type', $type->value)
            ->whereNotNull('active_marker')
            ->exists();
    }
}
