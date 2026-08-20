<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Testing;

use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;

/**
 * A measurer that reports whatever a test told it to.
 *
 * Shipped alongside the real one rather than living in `tests/`, for the reason
 * ADR-0003 gives about every other fake here: it is part of the module's
 * contract that a measurement can be supplied without a real file, and a test
 * double that lives outside the module drifts from the interface it doubles.
 *
 * It also makes the CMYK path testable at all. Writing a genuine CMYK JPEG
 * requires an image library this platform deliberately does not depend on.
 */
final class FakeImageMeasurer implements ImageMeasurer
{
    public function __construct(
        private ?ArtworkMeasurement $measurement = null,
        private bool $available = true,
        private string $version = '1',
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function measure(string $path): ?ArtworkMeasurement
    {
        return $this->measurement;
    }

    public function reports(?ArtworkMeasurement $measurement): self
    {
        $this->measurement = $measurement;

        return $this;
    }

    public function atVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }
}
