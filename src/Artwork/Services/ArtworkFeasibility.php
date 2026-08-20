<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\ArtworkRequirements;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\ImageSize;

/**
 * Whether the configured provider can produce something this installation
 * would accept — asked before anything is sent.
 *
 * **This is the point of ART-002.** Reading the published image specification
 * turned up a mismatch that a generator built without this check would have
 * discovered by spending money: the documented square sizes of the image models
 * available today are considerably smaller than this platform's default artwork
 * requirement of a 3000px shortest side. Generate first and validate later, and
 * every cover is paid for and then rejected by the platform's own validator.
 *
 * So the two configurations are compared directly. Both are settings, so an
 * operator has two honest ways out — lower the requirement, or configure a
 * provider that can reach it — and the refusal says which numbers disagreed
 * rather than only that they did.
 *
 * A requirement that is not set is not a requirement. An installation with no
 * minimum and no squareness rule can use any provider that produces anything.
 */
final readonly class ArtworkFeasibility
{
    public function __construct(private ImageProvider $provider) {}

    /**
     * The size to ask for, or null when nothing the provider makes would pass.
     */
    public function bestUsableSize(): ?ImageSize
    {
        $requirements = ArtworkRequirements::fromConfiguration();
        $capabilities = $this->provider->capabilities();

        $candidates = $requirements->mustBeSquare
            ? array_values(array_filter($capabilities->sizes, static fn (ImageSize $s): bool => $s->isSquare()))
            : $capabilities->sizes;

        $best = null;

        foreach ($candidates as $size) {
            if ($requirements->minimumPixels !== null && $size->shortestSide() < $requirements->minimumPixels) {
                continue;
            }

            if ($requirements->maximumPixels !== null && max($size->width, $size->height) > $requirements->maximumPixels) {
                continue;
            }

            // The largest that still passes. A cover is scaled down cleanly and
            // up badly, so the biggest acceptable option is the right default.
            if ($best === null || $size->shortestSide() > $best->shortestSide()) {
                $best = $size;
            }
        }

        return $best;
    }

    public function isFeasible(): bool
    {
        return $this->bestUsableSize() instanceof ImageSize;
    }

    /**
     * The numbers that disagree, for a refusal a person can act on.
     *
     * @return array<string, int|string|null>
     */
    public function explain(): array
    {
        $requirements = ArtworkRequirements::fromConfiguration();
        $capabilities = $this->provider->capabilities();
        $largestSquare = $capabilities->largestSquare();

        return [
            'provider' => $this->provider->name(),
            'required_minimum_px' => $requirements->minimumPixels,
            'requires_square' => $requirements->mustBeSquare ? 'true' : 'false',
            'provider_largest_square_px' => $largestSquare?->width,
            'provider_largest_shortest_side_px' => $capabilities->largestShortestSide(),
        ];
    }
}
