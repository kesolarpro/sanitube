<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

/**
 * One audio variant, as the provider describes it.
 *
 * Separate from {@see GenerationResult}, which is the state of the *job*. A
 * job has one status and several variants, and collapsing the two would make
 * "completed" a property of each take rather than of the request.
 *
 * `sourceReference` is where the provider is currently serving the audio. It
 * is a transfer detail with an expiry, not an address the catalogue may keep:
 * nothing is catalogue data until the Asset Registry has fetched and hashed it.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>  $raw  provider payload, kept for audit and provenance
     */
    public function __construct(
        public string $providerResultId,
        public ?string $sourceReference = null,
        public ?string $title = null,
        public ?int $durationMs = null,
        public array $raw = [],
    ) {}

    public function hasDownloadableAudio(): bool
    {
        return $this->sourceReference !== null && trim($this->sourceReference) !== '';
    }
}
