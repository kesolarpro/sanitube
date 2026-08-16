<?php

declare(strict_types=1);

namespace SaniTube\Releases\Services;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Foundation\Validation\ValidationResult;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Models\Release;

/**
 * Everything wrong with a release, split into what blocks it and what does not.
 *
 * `Release::readinessProblems()` from ARCH-002 answers "may this be READY?"
 * and is the authority on I4 — this does not replace it and does not relax it.
 * What it adds is the half a label actually works from: the *warnings*, and the
 * ability to ask at any point rather than only at the moment of committing.
 *
 * A validator that runs once, at the end, is a validator nobody uses. A
 * validator that reports a missing catalogue number with the same weight as a
 * missing cover trains its reader to ignore the list.
 *
 * **Errors** are things every store rejects. **Warnings** are things a label
 * will probably want to fix and can legitimately ship without.
 */
final readonly class ValidateRelease
{
    public function handle(Release $release): ValidationResult
    {
        return new ValidationResult([
            ...$this->errors($release),
            ...$this->warnings($release),
        ]);
    }

    /**
     * @return list<ValidationIssue>
     */
    private function errors(Release $release): array
    {
        // I4 first and unmodified. Anything this ticket adds is additional to
        // it, never a substitute — relaxing a validated invariant to make a
        // builder feel smoother is how an invariant stops being one.
        // I4 first and unmodified — the result deduplicates on (code, path),
        // so a problem both this and readinessProblems() find is reported once.
        return array_merge(
            $release->readinessProblems(),
            $this->tracklistErrors($release),
            $this->coverErrors($release),
        );
    }

    /**
     * @return list<ValidationIssue>
     */
    private function tracklistErrors(Release $release): array
    {
        $errors = [];
        $entries = $release->trackEntries()->get();

        if ($entries->isEmpty()) {
            // Already reported by I4 under the same code, and deduplicated on
            // the way out.
            return [ValidationIssue::error('TRACKS_REQUIRED', 'tracks', 'A release needs at least one track.')];
        }

        foreach ($entries->groupBy('disc_number') as $disc => $onDisc) {
            $numbers = $onDisc->pluck('track_number')->map(intval(...))->sort()->values()->all();

            if (count(array_unique($numbers)) !== count($numbers)) {
                $errors[] = ValidationIssue::error(
                    'DUPLICATE_TRACK_NUMBER',
                    'tracks.'.(string) $disc,
                    sprintf('Disc %s has two tracks with the same number.', $disc),
                    ['disc' => (string) $disc],
                );
            }

            // A tracklist running 1, 2, 4 is rejected on delivery. Checking it
            // here means the label sees it while it is still fixable.
            $expected = range(1, count($numbers));

            if (array_values(array_unique($numbers)) !== $expected) {
                $errors[] = ValidationIssue::error(
                    'DISC_NUMBERING_NOT_CONTIGUOUS',
                    'tracks.'.(string) $disc,
                    sprintf(
                        'Disc %s is not numbered continuously from 1 — it runs %s.',
                        $disc,
                        implode(', ', $numbers),
                    ),
                    ['disc' => (string) $disc, 'found' => implode(', ', $numbers)],
                );
            }
        }

        // Releasability is not re-checked here. `readinessProblems()` already
        // reports it, keyed by the release entry, and a second check keyed by
        // the track would slip past the deduplicator and tell a label the same
        // thing twice under two different paths.

        return $errors;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function coverErrors(Release $release): array
    {
        $cover = $release->coverAsset;

        if ($cover === null) {
            return [];
        }

        $errors = [];

        if ($cover->kind !== AssetKind::Artwork) {
            $errors[] = ValidationIssue::error(
                'COVER_NOT_ARTWORK',
                'cover',
                sprintf('The cover is a %s asset, not artwork.', $cover->kind->value),
                ['kind' => $cover->kind->value],
            );
        }

        if ($cover->status !== AssetStatus::Verified) {
            $errors[] = ValidationIssue::error(
                'COVER_NOT_VERIFIED',
                'cover',
                sprintf(
                    'The cover has not been verified — it is %s. Delivering artwork nobody has confirmed '
                        .'is how a store receives a corrupt image.',
                    $cover->status->value,
                ),
                ['status' => $cover->status->value],
            );
        }

        return $errors;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function warnings(Release $release): array
    {
        $warnings = [];

        if ($release->label_name === null || $release->label_name === '') {
            $warnings[] = ValidationIssue::warning(
                'NO_LABEL_NAME',
                'label_name',
                'No label name. Most stores display one, and an empty field shows as blank.',
            );
        }

        if ($release->catalogue_number === null || $release->catalogue_number === '') {
            $warnings[] = ValidationIssue::warning(
                'NO_CATALOGUE_NUMBER',
                'catalogue_number',
                'No catalogue number. Not required, but it is how a label finds this release in a '
                    .'distributor\'s own reporting.',
            );
        }

        if ($release->p_line === null || $release->c_line === null) {
            $warnings[] = ValidationIssue::warning(
                'MISSING_COPYRIGHT_LINE',
                'p_line',
                'Missing a ℗ or © line. Several stores fall back to the label name, which is rarely '
                    .'what the rights holder wants printed.',
            );
        }

        if ($release->language_code === ContentLanguage::UNKNOWN) {
            $warnings[] = ValidationIssue::warning(
                'LANGUAGE_UNDETERMINED',
                'language_code',
                'The release language is undetermined. It is honest, but a real code improves how the '
                    .'release is surfaced.',
            );
        }

        $trackCount = $release->tracks()->count();

        if ($trackCount > 1 && $release->trackEntries()->where('is_focus_track', true)->count() === 0) {
            $warnings[] = ValidationIssue::warning(
                'NO_FOCUS_TRACK',
                'tracks',
                'No focus track. On a multi-track release this is what stores promote, and without one '
                    .'they choose for you.',
            );
        }

        return array_merge($warnings, $this->trackWarnings($release));
    }

    /**
     * @return list<ValidationIssue>
     */
    private function trackWarnings(Release $release): array
    {
        $warnings = [];

        foreach ($release->tracks()->get() as $track) {
            /** @var Track $track */
            if ($track->genre_primary === null || $track->genre_primary === '') {
                $warnings[] = ValidationIssue::warning(
                    'TRACK_NO_GENRE',
                    'tracks.'.$track->uuid,
                    sprintf('Track "%s" has no primary genre.', $track->title),
                    ['title' => $track->title],
                );
            }

            if ($track->duration_ms === null) {
                // MED-001 measures this. Its absence usually means the host
                // has no FFmpeg rather than that anything is wrong.
                $warnings[] = ValidationIssue::warning(
                    'TRACK_NO_DURATION',
                    'tracks.'.$track->uuid,
                    sprintf(
                        'Track "%s" has no measured duration. Run sanitube:media:analyze if FFmpeg is available.',
                        $track->title,
                    ),
                    ['title' => $track->title],
                );
            }
        }

        return $warnings;
    }
}
