<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

/**
 * A requested image size, as `WIDTHxHEIGHT`.
 *
 * A type rather than a string because the wire format is a string and the
 * platform needs to reason about the numbers — specifically whether a provider
 * can produce something big enough to satisfy this installation's own artwork
 * requirements. Comparing `'1024x1024'` to `3000` is not a comparison anybody
 * should be writing twice.
 */
final readonly class ImageSize
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}

    public static function square(int $edge): self
    {
        return new self($edge, $edge);
    }

    /**
     * Parse `WIDTHxHEIGHT`, or null when it is not one.
     *
     * Null rather than an exception: this parses configuration, and a
     * mistyped setting should be reported as unusable rather than crash a
     * screen.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match('/^(\d{1,5})x(\d{1,5})$/', trim(strtolower($value)), $matches) !== 1) {
            return null;
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];

        return $width > 0 && $height > 0 ? new self($width, $height) : null;
    }

    public function isSquare(): bool
    {
        return $this->width === $this->height;
    }

    public function shortestSide(): int
    {
        return min($this->width, $this->height);
    }

    public function __toString(): string
    {
        return $this->width.'x'.$this->height;
    }
}
