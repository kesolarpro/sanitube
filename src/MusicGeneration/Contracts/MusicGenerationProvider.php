<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;

/**
 * A source of generated music.
 *
 * SaniTube owns the catalogue; a generation provider is a supplier, never the
 * centre of the system. Suno is the first intended implementation, but the
 * platform must be fully developable and testable before any such API is
 * available — hence {@see FakeMusicGenerationProvider}.
 *
 * Generation is asynchronous by nature: `generate()` starts a job and returns
 * immediately; the result is collected later by polling or by webhook.
 *
 * Provisional contract (ARCH-001). It will gain `extend()`, `remix()` and
 * `stems()` in GEN-001, once a real provider's semantics are known — guessing
 * them now would bake one vendor's model into the domain.
 */
interface MusicGenerationProvider
{
    /**
     * Configuration name, e.g. "suno", "fake".
     */
    public function name(): string;

    /**
     * Whether this provider can be used right now (credentials present,
     * terms accepted). A provider that is merely unconfigured must report
     * false rather than throwing at call time.
     */
    public function isAvailable(): bool;

    /**
     * Start a generation. Returns a result in a pending state carrying the
     * provider's job identifier.
     */
    public function generate(GenerationRequest $request): GenerationResult;

    /**
     * Current state of a previously started generation.
     */
    public function status(string $providerJobId): GenerationResult;

    /**
     * Ask the provider to stop a generation. Returns false when the provider
     * does not support cancellation or the job has already finished.
     */
    public function cancel(string $providerJobId): bool;
}
