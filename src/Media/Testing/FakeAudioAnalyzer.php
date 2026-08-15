<?php

declare(strict_types=1);

namespace SaniTube\Media\Testing;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Storage\Testing\InMemoryStorageProvider;

/**
 * An analyser that answers whatever a test needs it to.
 *
 * Shipped rather than left in the test directory, for the reason
 * {@see InMemoryStorageProvider} is: a contract with
 * one implementation is a contract shaped around that implementation. A second
 * one, written against the interface and checked by static analysis, is what
 * keeps `AudioAnalyzer` describing *analysis* instead of describing ffprobe.
 *
 * It also records the path it was handed and the bytes that were there. The
 * awkward part of {@see AnalyzeAsset} is that it must
 * stream an object out of provider-owned storage into a real local file before
 * any probe can open it, and the only way to prove that happened is to look
 * from inside the analyser while it is happening.
 */
final class FakeAudioAnalyzer implements AudioAnalyzer
{
    /** How many times a file was actually handed over. */
    public int $calls = 0;

    /** The path of the last file analysed, after the fact. */
    public ?string $lastPath = null;

    /** What was in that file at the moment it was analysed. */
    public ?string $lastContents = null;

    private bool $available = true;

    private ?AudioAnalysisResult $result = null;

    private string $version = '1';

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function analyze(string $localPath): AudioAnalysisResult
    {
        $this->calls++;
        $this->lastPath = $localPath;
        $this->lastContents = is_file($localPath) ? (string) file_get_contents($localPath) : null;

        return $this->result ?? AudioAnalysisResult::measured(
            durationMs: 213_546,
            codec: 'pcm_s24le',
            container: 'wav',
            sampleRate: 48_000,
            bitDepth: 24,
            channels: 2,
            bitrate: 2_304_000,
        );
    }

    /**
     * Behave like a server with no FFmpeg.
     */
    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function willReturn(AudioAnalysisResult $result): self
    {
        $this->result = $result;

        return $this;
    }

    /**
     * Pose as a later release of the same analyser, so that re-analysis at a
     * new version can be exercised.
     */
    public function atVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }
}
