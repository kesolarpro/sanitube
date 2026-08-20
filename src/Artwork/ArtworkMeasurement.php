<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

/**
 * What was actually measured from an image file.
 *
 * MEASURED, in ADR-0016's sense: every field here came from reading the bytes,
 * not from what somebody typed or what a filename suggested. A cover that
 * claims to be 3000×3000 in a spreadsheet and is 1400×1400 on disk is rejected
 * by a store, and the spreadsheet is not what gets delivered.
 *
 * **Every field is nullable except the two that always exist.** A PNG carries
 * no channel count, and reporting `0` there would be a number nothing measured
 * — the distinction between "three channels" and "nobody could tell" is the
 * whole reason this platform separates measurement from assertion.
 */
final readonly class ArtworkMeasurement
{
    public function __construct(
        public int $widthPx,
        public int $heightPx,
        public string $mimeType,
        public int $bytes,

        /**
         * Colour channels, when the format records them.
         *
         * Only JPEG does, in practice. Four channels means CMYK, which stores
         * reject or silently convert — and a silent conversion is how artwork
         * arrives with colours the designer never chose.
         */
        public ?int $channels = null,

        /** Bits per channel, when the format records it. */
        public ?int $bits = null,
    ) {}

    public function isSquare(): bool
    {
        return $this->widthPx === $this->heightPx;
    }

    /**
     * The shorter side, which is what a minimum-dimension rule is really about:
     * a 4000×1000 image satisfies "at least 3000 wide" and is not usable.
     */
    public function shortestSide(): int
    {
        return min($this->widthPx, $this->heightPx);
    }

    /**
     * Whether the file is stored in CMYK, or null when the format does not say.
     *
     * Null is not "no". A PNG records no channel count, so nothing was learned;
     * answering `false` would assert something nobody measured.
     */
    public function isCmyk(): ?bool
    {
        return $this->channels === null ? null : $this->channels === 4;
    }
}
