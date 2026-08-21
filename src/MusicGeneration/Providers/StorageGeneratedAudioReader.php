<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use RuntimeException;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\Storage\StorageManager;

/**
 * Opens generated audio that a worker has already staged.
 *
 * The reader for the self-hosted path, and the reason it exists is that the
 * bytes never travel through the worker-to-Core hop at all: the worker put them
 * in the storage both sides share, and Core streams them out of it. On a
 * single-host installation that storage is the local disk and this is a file
 * read; on a split one it is R2 and this is the same code.
 *
 * Delegates to {@see HttpGeneratedAudioReader} for anything that is not a
 * storage reference, so an installation can run a hosted provider and a
 * self-hosted one at the same time without choosing a reader.
 *
 * A stream, never a string. A rendered master must not have to fit in PHP's
 * memory.
 */
final readonly class StorageGeneratedAudioReader implements GeneratedAudioReader
{
    /**
     * The marker that says "this is a key in our own storage".
     *
     * A scheme rather than a bare key, so that a reference can be told apart
     * from a URL by looking at it — and so that a provider which one day
     * returns a real URL is not mistaken for this one.
     */
    public const SCHEME = 'sanitube-storage://';

    public function __construct(
        private StorageManager $storage,
        private HttpGeneratedAudioReader $http,
    ) {}

    /**
     * @return resource
     */
    public function open(string $sourceReference)
    {
        if (! str_starts_with($sourceReference, self::SCHEME)) {
            return $this->http->open($sourceReference);
        }

        $key = substr($sourceReference, strlen(self::SCHEME));

        if (trim($key) === '') {
            throw new RuntimeException('The generated audio reference carries no storage key.');
        }

        return $this->storage->default()->readStream($key);
    }
}
