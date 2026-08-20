<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Measurers;

use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;

/**
 * The measurer for an installation that cannot measure.
 *
 * A null object rather than a null: every caller then reads one type, and
 * "this platform cannot measure images" is answered by `isAvailable()` rather
 * than by a fatal error somewhere downstream.
 */
final readonly class UnavailableImageMeasurer implements ImageMeasurer
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function version(): string
    {
        return '0';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function measure(string $path): ?ArtworkMeasurement
    {
        return null;
    }
}
