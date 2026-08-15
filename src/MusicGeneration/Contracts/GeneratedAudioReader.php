<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

use SaniTube\MusicGeneration\Providers\HttpGeneratedAudioReader;

/**
 * Opens the audio a provider is serving.
 *
 * A seam rather than an inline `fopen`, because "where the audio is" differs
 * per provider — a signed URL, a bare path, an object in the provider's own
 * bucket — and because a test must be able to produce bytes without a network.
 * The rest of the selection path is identical either way.
 *
 * @see HttpGeneratedAudioReader
 */
interface GeneratedAudioReader
{
    /**
     * @return resource
     */
    public function open(string $sourceReference);
}
