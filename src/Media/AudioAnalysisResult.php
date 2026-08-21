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

    /**
     * Rebuild a result a worker measured.
     *
     * WRK-002. The worker runs the same tool and returns the same facts; this
     * is where they come back across the boundary, and every field is read
     * defensively because it arrived over a network from another machine.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (($payload['succeeded'] ?? false) !== true) {
            $message = $payload['failure_message'] ?? null;

            return self::failed(is_string($message) && trim($message) !== '' ? $message : 'WORKER_REPORTED_FAILURE');
        }

        return self::measured(
            durationMs: self::integer($payload['duration_ms'] ?? null),
            codec: self::text($payload['codec'] ?? null),
            container: self::text($payload['container'] ?? null),
            sampleRate: self::integer($payload['sample_rate'] ?? null),
            bitDepth: self::integer($payload['bit_depth'] ?? null),
            channels: self::integer($payload['channels'] ?? null),
            bitrate: self::integer($payload['bitrate'] ?? null),
        );
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 64) : null;
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
