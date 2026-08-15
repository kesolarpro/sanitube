<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use RuntimeException;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;

/**
 * Fetches generated audio over HTTP.
 *
 * The default reader, and the one a real provider needs: generation APIs serve
 * results from expiring URLs. Opened as a stream rather than read into memory,
 * because a shared host with a 128 MB limit cannot hold a rendered master and
 * the asset pipeline wants a stream anyway.
 */
final readonly class HttpGeneratedAudioReader implements GeneratedAudioReader
{
    /**
     * @return resource
     */
    public function open(string $sourceReference)
    {
        $context = stream_context_create([
            'http' => ['timeout' => max(1, (int) config('generation.download_timeout', 120))],
        ]);

        $stream = @fopen($sourceReference, 'rb', false, $context);

        if ($stream === false) {
            throw new RuntimeException('The generated audio could not be opened for reading.');
        }

        return $stream;
    }
}
