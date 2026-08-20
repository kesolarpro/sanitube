<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Providers;

use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageCapabilities;
use SaniTube\Artwork\ImageRequest;

/**
 * The provider for an installation with no image provider.
 *
 * A null object rather than a null, so every caller reads one type and "this
 * installation cannot generate artwork" is answered by `isAvailable()` rather
 * than by a fatal error further down.
 */
final readonly class UnavailableImageProvider implements ImageProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function capabilities(): ImageCapabilities
    {
        return ImageCapabilities::none();
    }

    public function generate(ImageRequest $request): ?GeneratedImage
    {
        return null;
    }
}
