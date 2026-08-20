<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

use SaniTube\Artwork\Services\ArtworkFeasibility;

/**
 * What sizes and formats a provider will actually produce.
 *
 * **Declared, never inferred.** The platform does not work out what a model can
 * do from its name, and it does not assume that because a specification mentions
 * a large resolution somewhere, a *square* of that edge is obtainable. What a
 * provider can produce is read from configuration, where an operator can correct
 * it against the vendor's current documentation without touching code.
 *
 * The reason this type exists at all is a mismatch worth stating plainly: this
 * platform's default artwork requirement is a 3000px shortest side, and the
 * documented square sizes of the image models available today are considerably
 * smaller. A generator that happily spends money producing covers its own
 * validator will then reject is worse than no generator, so
 * {@see ArtworkFeasibility} compares the two *before*
 * anything is sent.
 */
final readonly class ImageCapabilities
{
    /**
     * @param  list<ImageSize>  $sizes  the sizes this provider is configured to produce
     * @param  list<string>  $outputMimeTypes
     */
    public function __construct(
        public array $sizes,
        public array $outputMimeTypes,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * The largest square this provider can produce, or null if it produces no
     * square at all.
     *
     * Squares specifically. Release artwork is square nearly everywhere, and a
     * provider whose biggest output is 1792x1024 can satisfy a 1024 minimum
     * only if the requirement does not also demand a square.
     */
    public function largestSquare(): ?ImageSize
    {
        $largest = null;

        foreach ($this->sizes as $size) {
            if (! $size->isSquare()) {
                continue;
            }

            if ($largest === null || $size->width > $largest->width) {
                $largest = $size;
            }
        }

        return $largest;
    }

    /**
     * The largest shortest-side across every configured size, square or not.
     */
    public function largestShortestSide(): int
    {
        $largest = 0;

        foreach ($this->sizes as $size) {
            $largest = max($largest, $size->shortestSide());
        }

        return $largest;
    }

    public function producesNothing(): bool
    {
        return $this->sizes === [];
    }
}
