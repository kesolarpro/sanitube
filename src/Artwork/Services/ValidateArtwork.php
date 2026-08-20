<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\ArtworkRequirements;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Validation\ValidationIssue;

/**
 * Judges a measured cover against the configured requirements.
 *
 * **It measures nothing itself, and that is deliberate.** Validation runs every
 * time a release screen is drawn; streaming a cover down from object storage on
 * each of those would make an ordinary page load an object-storage bill. The
 * measurement is taken once by {@see MeasureArtwork} in a queued job and read
 * here.
 *
 * **Three states, not two.** Artwork is not only valid or invalid:
 *
 * - **Measured and conforming** — no issue.
 * - **Measured and not conforming** — an error, with the measured value in the
 *   context so a person can see *why* rather than being told to try again.
 * - **Not measured** — a warning, never a pass and never an error. Nobody has
 *   looked, and reporting that as conforming is how a release is delivered with
 *   a 600×600 cover that had simply never been checked.
 *
 * A failed measurement is the fourth case and is an *error*: somebody looked
 * and the file is not a readable image. Collapsing it into "not measured" would
 * turn a corrupt cover into a suggestion.
 *
 * Codes, never English, are the contract — REL-002's rule. The messages here
 * are for a log and for a developer; the interface translates the code.
 */
final readonly class ValidateArtwork
{
    public function __construct(private ImageMeasurer $measurer) {}

    /**
     * @return list<ValidationIssue>
     */
    public function handle(Asset $artwork): array
    {
        $analysis = ArtworkAnalysis::query()
            ->where('asset_id', $artwork->id)
            ->orderByDesc('id')
            ->first();

        if (! $analysis instanceof ArtworkAnalysis) {
            return [$this->notMeasured($artwork)];
        }

        $measurement = $analysis->measurement();

        if ($measurement === null) {
            return [ValidationIssue::error(
                'COVER_UNREADABLE',
                'cover',
                'The cover could not be read as an image. A store reads the bytes, not the extension.',
                ['measurer' => $analysis->measurer],
            )];
        }

        return $this->judge($measurement);
    }

    /**
     * @return list<ValidationIssue>
     */
    private function judge(ArtworkMeasurement $measurement): array
    {
        $requirements = ArtworkRequirements::fromConfiguration();
        $issues = [];

        if ($requirements->mustBeSquare && ! $measurement->isSquare()) {
            $issues[] = ValidationIssue::error(
                'COVER_NOT_SQUARE',
                'cover',
                'The cover is not square.',
                ['width_px' => $measurement->widthPx, 'height_px' => $measurement->heightPx],
            );
        }

        // The *shortest* side. A 4000x1000 image satisfies "at least 3000
        // wide" and is not usable as a cover.
        if ($requirements->minimumPixels !== null && $measurement->shortestSide() < $requirements->minimumPixels) {
            $issues[] = ValidationIssue::error(
                'COVER_TOO_SMALL',
                'cover',
                'The cover is smaller than this installation requires.',
                [
                    'shortest_side_px' => $measurement->shortestSide(),
                    'minimum_px' => $requirements->minimumPixels,
                ],
            );
        }

        if ($requirements->maximumPixels !== null && max($measurement->widthPx, $measurement->heightPx) > $requirements->maximumPixels) {
            $issues[] = ValidationIssue::error(
                'COVER_TOO_LARGE',
                'cover',
                'The cover is larger than this installation allows.',
                [
                    'longest_side_px' => max($measurement->widthPx, $measurement->heightPx),
                    'maximum_px' => $requirements->maximumPixels,
                ],
            );
        }

        if (! $requirements->accepts($measurement->mimeType)) {
            $issues[] = ValidationIssue::error(
                'COVER_FORMAT_NOT_ACCEPTED',
                'cover',
                'The cover is not one of the accepted image formats.',
                [
                    'mime_type' => $measurement->mimeType,
                    // Joined, not passed as a list. The audit scrubber keeps
                    // only keys matching its name pattern, so a list's integer
                    // keys are dropped and the context arrives empty.
                    'accepted' => implode(',', $requirements->acceptedMimeTypes),
                ],
            );
        }

        if ($requirements->maximumBytes !== null && $measurement->bytes > $requirements->maximumBytes) {
            $issues[] = ValidationIssue::error(
                'COVER_FILE_TOO_LARGE',
                'cover',
                'The cover file is larger than this installation allows.',
                ['bytes' => $measurement->bytes, 'maximum_bytes' => $requirements->maximumBytes],
            );
        }

        $issues = array_merge($issues, $this->colourIssues($measurement, $requirements));

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function colourIssues(ArtworkMeasurement $measurement, ArtworkRequirements $requirements): array
    {
        if (! $requirements->refusesCmyk) {
            return [];
        }

        $isCmyk = $measurement->isCmyk();

        // Null is not "no". Only formats that record a channel count can be
        // judged, and asserting RGB for a PNG would be a claim nothing
        // measured. Silence is the honest answer, because the alternative --
        // warning on every PNG -- would train a reader to ignore the list.
        if ($isCmyk !== true) {
            return [];
        }

        return [ValidationIssue::error(
            'COVER_IS_CMYK',
            'cover',
            'The cover is CMYK. Stores reject it or convert it silently, and a silent conversion '
                .'delivers colours the designer never chose.',
            ['channels' => $measurement->channels],
        )];
    }

    private function notMeasured(Asset $artwork): ValidationIssue
    {
        // Deliberately a warning even though a delivery with unchecked artwork
        // is a real risk. An installation with no measurer would otherwise
        // report every release as broken -- the same reasoning that keeps a
        // missing audio analysis from blocking a catalogue.
        return ValidationIssue::warning(
            'COVER_NOT_MEASURED',
            'cover',
            $this->measurer->isAvailable()
                ? 'Nobody has measured this cover yet, so its size and format are unknown.'
                : 'This installation cannot measure images, so the cover\'s size and format are unknown.',
            [
                'asset_uuid' => $artwork->uuid,
                'measurer_available' => $this->measurer->isAvailable(),
            ],
        );
    }
}
