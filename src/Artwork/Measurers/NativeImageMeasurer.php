<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Measurers;

use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;

/**
 * Measurement by PHP's own image inspection.
 *
 * **Deliberately not GD, and not Imagick.** `getimagesize()` lives in
 * ext-standard and is present on every PHP build, including the shared hosting
 * this platform is designed to run on. It reads the header rather than decoding
 * the image, so a 40MB master costs a few bytes of I/O rather than 40MB of
 * memory — which matters on a host with a 128MB limit.
 *
 * What it cannot do is read an embedded colour profile, so "is this sRGB" is a
 * question this measurer does not answer and does not pretend to. It reports
 * the channel count, which distinguishes CMYK from RGB for JPEG and says
 * nothing at all for PNG — and `null` is how it says nothing.
 */
final readonly class NativeImageMeasurer implements ImageMeasurer
{
    public function name(): string
    {
        return 'native';
    }

    public function version(): string
    {
        return '1';
    }

    public function isAvailable(): bool
    {
        return function_exists('getimagesize');
    }

    public function measure(string $path): ?ArtworkMeasurement
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        // Warnings suppressed rather than allowed to surface: a file that is
        // not an image emits one, and that is an expected outcome here, not a
        // problem to report through the error handler.
        $size = @getimagesize($path);

        if (! is_array($size)) {
            return null;
        }

        $width = $size[0];
        $height = $size[1];

        // A zero dimension is what a truncated or corrupt header produces, and
        // reporting it as a measurement would give the validator a size that
        // fails every rule for the wrong reason.
        if ($width < 1 || $height < 1) {
            return null;
        }

        $bytes = filesize($path);

        return new ArtworkMeasurement(
            widthPx: $width,
            heightPx: $height,
            mimeType: $size['mime'],
            bytes: is_int($bytes) ? $bytes : 0,
            channels: is_int($size['channels'] ?? null) ? $size['channels'] : null,
            bits: is_int($size['bits'] ?? null) ? $size['bits'] : null,
        );
    }
}
