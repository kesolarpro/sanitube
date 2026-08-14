<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

/**
 * Artwork handling needs GD or Imagick: DSPs reject covers that are not
 * square, large enough and in the right colour space.
 */
final readonly class ImageProcessingDetector implements CapabilityDetector
{
    public function detect(): Capability
    {
        $drivers = array_values(array_filter([
            extension_loaded('imagick') ? 'Imagick' : null,
            extension_loaded('gd') ? 'GD' : null,
        ]));

        if ($drivers === []) {
            return Capability::unavailable(
                key: 'image_processing',
                label: 'Image processing',
                detail: 'Neither Imagick nor GD is loaded.',
                remediation: 'Enable the GD or Imagick extension. Artwork validation and resizing '
                    .'cannot run without one of them.',
            );
        }

        return Capability::available(
            key: 'image_processing',
            label: 'Image processing',
            detail: implode(' and ', $drivers).' available.',
        );
    }
}
