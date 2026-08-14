<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

/**
 * The state of a generation as the provider last reported it.
 *
 * `audioUrl` is where the provider is currently serving the audio; it is a
 * transfer detail, not an asset. Nothing is part of the catalogue until it has
 * been fetched, hashed and registered in the Asset Registry.
 */
final readonly class GenerationResult
{
    /**
     * @param  array<string, mixed>  $raw  provider payload, kept for audit and provenance
     */
    public function __construct(
        public string $provider,
        public string $providerJobId,
        public GenerationStatus $status,
        public ?string $audioUrl = null,
        public ?string $title = null,
        public ?int $durationSeconds = null,
        public ?string $model = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status === GenerationStatus::Completed;
    }

    /**
     * A completed generation with nothing to download is a provider bug; the
     * ingestion pipeline must treat it as a failure rather than an empty track.
     */
    public function hasDownloadableAudio(): bool
    {
        return $this->isSuccessful() && $this->audioUrl !== null && $this->audioUrl !== '';
    }
}
