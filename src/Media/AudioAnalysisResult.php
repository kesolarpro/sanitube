<?php

declare(strict_types=1);

namespace SaniTube\Media;

/**
 * What an analyser found, or why it found nothing.
 *
 * Every measurement is nullable on purpose. A container that does not record
 * bit depth is not a broken file, and a codec that has no fixed bitrate is not
 * a failed analysis — inventing a zero for either would put a number into the
 * catalogue that nothing measured.
 */
final readonly class AudioAnalysisResult
{
    /**
     * @param  array<string, mixed>  $raw  the analyser's own output, kept for the questions nobody has asked yet
     */
    private function __construct(
        public bool $succeeded,
        public ?int $durationMs = null,
        public ?string $codec = null,
        public ?string $container = null,
        public ?int $sampleRate = null,
        public ?int $bitDepth = null,
        public ?int $channels = null,
        public ?int $bitrate = null,
        public ?float $loudnessLufs = null,
        public ?float $peakDbfs = null,
        public array $raw = [],
        public ?string $failureMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function measured(
        ?int $durationMs = null,
        ?string $codec = null,
        ?string $container = null,
        ?int $sampleRate = null,
        ?int $bitDepth = null,
        ?int $channels = null,
        ?int $bitrate = null,
        ?float $loudnessLufs = null,
        ?float $peakDbfs = null,
        array $raw = [],
    ): self {
        return new self(
            succeeded: true,
            durationMs: $durationMs,
            codec: $codec,
            container: $container,
            sampleRate: $sampleRate,
            bitDepth: $bitDepth,
            channels: $channels,
            bitrate: $bitrate,
            loudnessLufs: $loudnessLufs,
            peakDbfs: $peakDbfs,
            raw: $raw,
        );
    }

    public static function failed(string $message): self
    {
        return new self(succeeded: false, failureMessage: $message);
    }

    /**
     * Whether anything was actually measured.
     *
     * A probe can succeed and still find no audio — an empty container, a
     * video file with no audio track. That is a failure of the *file* to be
     * what it claimed, and it should not be recorded as a successful analysis
     * with every field null.
     */
    public function isEmpty(): bool
    {
        return $this->durationMs === null && $this->codec === null && $this->sampleRate === null;
    }
}
