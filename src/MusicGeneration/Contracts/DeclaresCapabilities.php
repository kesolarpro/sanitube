<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;

/**
 * A provider that says what it can do.
 *
 * Optional on purpose, and that is the whole design. Adding `capabilities()`
 * to {@see MusicGenerationProvider} would have broken every existing adapter
 * and — worse — forced each one to answer thirteen questions at the moment it
 * was written, which is how a list of capabilities fills up with guesses. An
 * adapter that implements this has been read against its provider's published
 * contract; an adapter that does not gets the structural answer and nothing
 * more.
 *
 * The same reasoning that kept `extend`, `remix` and `stems` out of the base
 * contract as separate capability interfaces — see
 * {@see Capabilities\SupportsExtend} — applies one level up. This interface
 * does not replace those: {@see ProviderCapabilities} reads both, because
 * implementing `SupportsExtend` is a stronger claim than listing EXTEND. One
 * is a method that exists; the other is a string.
 */
interface DeclaresCapabilities
{
    /**
     * Everything this provider supports, as verified against its documentation.
     *
     * Structural capabilities may be omitted — the platform adds them — and
     * listing one that is contradicted by a capability interface is not an
     * error, because a set has no duplicates.
     *
     * An empty array is a valid and meaningful answer: it is what a provider
     * that is not really there returns.
     *
     * @return list<GenerationCapability>
     */
    public function capabilities(): array;
}
