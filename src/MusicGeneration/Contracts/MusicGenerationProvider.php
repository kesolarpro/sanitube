<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\ProviderResult;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;

/**
 * A source of generated music.
 *
 * SaniTube owns the catalogue; a generation provider is a supplier, never the
 * centre of the system. Suno is the first intended implementation, but the
 * platform must be fully developable and testable before any such API is
 * available — hence {@see FakeMusicGenerationProvider}.
 *
 * **Asynchronous, and no longer by assumption.** GEN-001 wrote "generation is
 * asynchronous by nature" and it was true of every supplier then in view;
 * GEN-006's research found one whose own reference server blocks and returns
 * the audio. So this is now one of two sub-contracts under
 * {@see GenerationProvider} rather than the only shape a supplier may have —
 * see {@see SynchronousMusicGenerationProvider} for the other.
 *
 * Here, `createGeneration()` starts a job and returns immediately; results are
 * collected later by polling.
 *
 * **Four methods, and no more.** ARCH-001 left this provisional pending a real
 * provider's semantics, and GEN-001 finalises it deliberately small.
 * `extend`, `remix` and `stems` are *not* here: no provider in the matrix
 * supports all three, they mean measurably different things where they exist,
 * and a base contract that demands them would force every adapter to implement
 * refusals for capabilities it never had. When one is needed it arrives as a
 * separate capability interface — {@see Capabilities\SupportsExtend} — which an
 * adapter implements or does not, and which callers test for.
 *
 * Note the split between {@see getGenerationStatus()} and {@see fetchResults()}.
 * Status is cheap and polled often; results are the payload and are fetched
 * once the job is done. Providers bill and rate-limit them differently, and a
 * contract that returned both together would make a status poll expensive.
 */
interface MusicGenerationProvider extends GenerationProvider
{
    /**
     * Start a generation. Returns a result in a pending state carrying the
     * provider's job identifier.
     */
    public function createGeneration(GenerationRequest $request): GenerationResult;

    /**
     * Current state of a previously started generation.
     */
    public function getGenerationStatus(string $providerJobId): GenerationResult;

    /**
     * The audio variants a finished job produced.
     *
     * A list, because one request routinely returns several takes and the
     * platform keeps all of them. Empty for a job that has not finished, and
     * empty for one that produced nothing — the caller distinguishes those by
     * asking for the status, which is why the two are separate calls.
     *
     * @return list<ProviderResult>
     */
    public function fetchResults(string $providerJobId): array;

    /**
     * Ask the provider to stop a generation. Returns false when the provider
     * does not support cancellation or the job has already finished.
     */
    public function cancelGeneration(string $providerJobId): bool;
}
