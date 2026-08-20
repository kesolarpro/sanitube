<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

/**
 * Anything that can be asked for music.
 *
 * **Two methods, and neither of them generates.** This is the part of a supplier
 * that is the same whatever shape it has: what it is called, and whether it can
 * be used right now. Everything about *how* it produces audio lives in a
 * sub-contract, because GEN-007 found that suppliers genuinely differ there and
 * a single interface had been quietly asserting they do not.
 *
 * Two sub-contracts exist today:
 *
 *   - {@see MusicGenerationProvider} — asynchronous. Ask, get a reference back,
 *     collect the result later. What most hosted suppliers do.
 *   - {@see SynchronousMusicGenerationProvider} — synchronous. Ask, and the
 *     answer is the audio. What ACE-Step's own reference server does.
 *
 * A provider implements one or the other, or — for a supplier that offers both —
 * both. It never implements a method it has no answer for, which is the whole
 * point of splitting them: the alternative was a synchronous adapter inventing
 * a job identifier so that something could poll for a result it already had.
 *
 * Anything that resolves a provider — the manager, capability discovery,
 * selection — works at this level. Only the two services that actually run a
 * generation care which sub-contract they were handed.
 */
interface GenerationProvider
{
    /**
     * Configuration name, e.g. "suno", "fake", "acestep".
     */
    public function name(): string;

    /**
     * Whether this provider can be used right now — credentials present, terms
     * accepted, host reachable as far as configuration can tell.
     *
     * A provider that is merely unconfigured must report false rather than
     * throwing at call time. A studio screen that cannot generate must still
     * render, and this is asked on a page load.
     */
    public function isAvailable(): bool;
}
