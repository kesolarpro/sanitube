<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Contracts;

use SaniTube\Artwork\ArtworkMeasurement;

/**
 * Reads an image file and reports what it is.
 *
 * A contract rather than a static call, for the reason ADR-0003 gives about
 * every other outside capability: an installation that cannot measure images
 * must be able to say so, and a test must be able to supply a measurement
 * without writing a real file.
 */
interface ImageMeasurer
{
    public function name(): string;

    /**
     * Bumped when the measurement itself would change, so a re-measure adds a
     * row beside the old one rather than overwriting what an earlier version
     * concluded.
     */
    public function version(): string;

    public function isAvailable(): bool;

    /**
     * @param  string  $path  a local file. Never a URL: no measurer may be
     *                        talked into fetching an arbitrary address.
     * @return ArtworkMeasurement|null null when the file is not an image this
     *                                 measurer can read. Not an exception —
     *                                 an unreadable file is an ordinary
     *                                 outcome and is recorded as one.
     */
    public function measure(string $path): ?ArtworkMeasurement;
}
