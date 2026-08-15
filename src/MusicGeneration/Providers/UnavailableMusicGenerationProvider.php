<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\GenerationStatus;

/**
 * The generation provider a fresh install gets.
 *
 * No music API is available to this platform yet — Suno is the first intended
 * adapter and has no credentials here — so this is not a placeholder for a
 * missing feature but the honest default state. Everything else in SaniTube
 * imports, analyses, catalogues and releases without it.
 *
 * It never throws. A Studio screen that cannot generate must still render.
 */
final readonly class UnavailableMusicGenerationProvider implements MusicGenerationProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult(
            provider: $this->name(),
            providerJobId: '',
            status: GenerationStatus::Failed,
            failureReason: 'No music generation provider is configured on this installation.',
        );
    }

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return $this->createGeneration(new GenerationRequest(prompt: ''));
    }

    public function fetchResults(string $providerJobId): array
    {
        return [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        return false;
    }
}
