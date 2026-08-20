<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Contracts;

use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageCapabilities;
use SaniTube\Artwork\ImageRequest;

/**
 * Somewhere that will produce an image from a description.
 */
interface ImageProvider
{
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * What this provider is configured to be able to produce.
     *
     * Part of the contract rather than a detail of one adapter, because the
     * platform refuses infeasible work before spending on it — and it can only
     * do that if every provider can be asked.
     */
    public function capabilities(): ImageCapabilities;

    /**
     * @return GeneratedImage|null null when nothing usable came back. Not an
     *                             exception: a vendor outage is an ordinary
     *                             condition for an optional feature.
     */
    public function generate(ImageRequest $request): ?GeneratedImage;
}
