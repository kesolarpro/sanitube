<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Testing;

use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageCapabilities;
use SaniTube\Artwork\ImageRequest;
use SaniTube\Artwork\ImageSize;

/**
 * An image provider that produces whatever a test told it to.
 *
 * Shipped alongside the real one, like every other fake here: it is part of the
 * module's contract that generation can be exercised end to end without a
 * vendor account, and a double living outside the module drifts from the
 * interface it doubles.
 */
final class FakeImageProvider implements ImageProvider
{
    /** @var list<ImageRequest> */
    public array $requests = [];

    /**
     * @param  list<ImageSize>  $sizes
     */
    public function __construct(
        private ?GeneratedImage $image = null,
        private array $sizes = [],
        private bool $available = true,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function capabilities(): ImageCapabilities
    {
        return new ImageCapabilities($this->sizes, ['image/png']);
    }

    public function generate(ImageRequest $request): ?GeneratedImage
    {
        // Recorded, so a test can prove not only what came back but that
        // nothing was asked for at all when the platform refuses.
        $this->requests[] = $request;

        return $this->image;
    }

    public function produces(?GeneratedImage $image): self
    {
        $this->image = $image;

        return $this;
    }
}
