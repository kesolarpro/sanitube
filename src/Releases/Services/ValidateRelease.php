<?php

declare(strict_types=1);

namespace SaniTube\Releases\Services;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\ReleaseValidationResult;

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
    public function handle(Release $release): ReleaseValidationResult
    {
        return new ReleaseValidationResult(
            errors: $this->errors($release),
            warnings: $this->warnings($release),
        );
    }

    /**
     * @return list<string>
     */
    private function errors(Release $release): array
    {
        // I4 first and unmodified. Anything this ticket adds is additional to
        // it, never a substitute — relaxing a validated invariant to make a
        // builder feel smoother is how an invariant stops being one.
        $errors = $release->readinessProblems();

        $errors = array_merge($errors, $this->tracklistErrors($release), $this->coverErrors($release));

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private function tracklistErrors(Release $release): array
    {
        $errors = [];
        $entries = $release->trackEntries()->get();

        if ($entries->isEmpty()) {
            // Already reported by I4, and deduplicated on the way out.
            return ['A release needs at least one track.'];
        }

        foreach ($entries->groupBy('disc_number') as $disc => $onDisc) {
            $numbers = $onDisc->pluck('track_number')->map(intval(...))->sort()->values()->all();

            if (count(array_unique($numbers)) !== count($numbers)) {
                $errors[] = sprintf('Disc %s has two tracks with the same number.', $disc);
            }

            // A tracklist running 1, 2, 4 is rejected on delivery. Checking it
            // here means the label sees it while it is still fixable.
            $expected = range(1, count($numbers));

            if (array_values(array_unique($numbers)) !== $expected) {
                $errors[] = sprintf(
                    'Disc %s is not numbered continuously from 1 — it runs %s.',
                    $disc,
                    implode(', ', $numbers),
                );
            }
        }

        foreach ($release->tracks()->get() as $track) {
            if (! $track->status->isReleasable()) {
                $errors[] = sprintf(
                    'Track "%s" is %s and cannot be released.',
                    $track->title,
                    $track->status->value,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function coverErrors(Release $release): array
    {
        $cover = $release->coverAsset;

        if ($cover === null) {
            return [];
        }

        $errors = [];

        if ($cover->kind !== AssetKind::Artwork) {
            $errors[] = sprintf('The cover is a %s asset, not artwork.', $cover->kind->value);
        }

        if ($cover->status !== AssetStatus::Verified) {
            $errors[] = sprintf(
                'The cover has not been verified — it is %s. Delivering artwork nobody has confirmed '
                    .'is how a store receives a corrupt image.',
                $cover->status->value,
            );
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function warnings(Release $release): array
    {
        $warnings = [];

        if ($release->label_name === null || $release->label_name === '') {
            $warnings[] = 'No label name. Most stores display one, and an empty field shows as blank.';
        }

        if ($release->catalogue_number === null || $release->catalogue_number === '') {
            $warnings[] = 'No catalogue number. Not required, but it is how a label finds this '
                .'release in a royalty statement.';
        }

        if ($release->p_line === null || $release->c_line === null) {
            $warnings[] = 'Missing a ℗ or © line. Several stores fall back to the label name, '
                .'which is rarely what the rights holder wants printed.';
        }

        if ($release->language_code === ContentLanguage::UNKNOWN) {
            $warnings[] = 'The release language is undetermined. It is honest, but a real code '
                .'improves how the release is surfaced.';
        }

        $trackCount = $release->tracks()->count();

        if ($trackCount > 1 && $release->trackEntries()->where('is_focus_track', true)->count() === 0) {
            $warnings[] = 'No focus track. On a multi-track release this is what stores promote, '
                .'and without one they choose for you.';
        }

        $warnings = array_merge($warnings, $this->trackWarnings($release));

        return array_values(array_unique($warnings));
    }

    /**
     * @return list<string>
     */
    private function trackWarnings(Release $release): array
    {
        $warnings = [];

        foreach ($release->tracks()->get() as $track) {
            /** @var Track $track */
            if ($track->genre_primary === null || $track->genre_primary === '') {
                $warnings[] = sprintf('Track "%s" has no primary genre.', $track->title);
            }

            if ($track->duration_ms === null) {
                // MED-001 measures this. Its absence usually means the host
                // has no FFmpeg rather than that anything is wrong.
                $warnings[] = sprintf(
                    'Track "%s" has no measured duration. Run sanitube:media:analyze if FFmpeg is available.',
                    $track->title,
                );
            }
        }

        return $warnings;
    }
}
