<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

/**
 * One request for a generated image.
 *
 * Provider-independent on purpose: `prompt` and `size` are what every image API
 * takes, and everything vendor-shaped — how transparency is asked for, whether
 * base64 or a URL comes back — stays inside the adapter.
 */
final readonly class ImageRequest
{
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ?string $quality = null,
        public ?string $background = null,
    ) {}
}
