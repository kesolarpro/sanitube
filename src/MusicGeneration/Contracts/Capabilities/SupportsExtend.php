<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts\Capabilities;

use SaniTube\MusicGeneration\GenerationResult;

/**
 * A provider that can continue an existing generation.
 *
 * Separate from the base contract on purpose. Extending means different things
 * across providers — continue from the end, regenerate a section, lengthen the
 * outro — and no provider in the matrix supports every variant. A base contract
 * demanding it would force every adapter to write a refusal for a capability
 * it never had, which is how an interface stops describing anything.
 *
 * Callers test for it: `$provider instanceof SupportsExtend`.
 */
interface SupportsExtend
{
    public function extendGeneration(string $providerJobId, ?int $fromMs = null): GenerationResult;
}
