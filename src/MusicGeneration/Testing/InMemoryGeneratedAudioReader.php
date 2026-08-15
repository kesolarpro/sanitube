<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Testing;

use RuntimeException;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;

/**
 * Serves generated audio from memory.
 *
 * Shipped rather than test-only, for the reason the in-memory storage provider
 * and the fake analyser are: a contract with one implementation is a contract
 * shaped around that implementation. It is also what lets the fake generation
 * provider demonstrate the whole selection path on an installation with no
 * provider account and no network.
 */
final class InMemoryGeneratedAudioReader implements GeneratedAudioReader
{
    /** @var array<string, string> */
    private array $audio = [];

    private bool $failing = false;

    /**
     * Any reference not explicitly stored returns this, so a test does not
     * have to register every take the fake provider invents.
     */
    public function __construct(private readonly string $default = 'generated audio bytes') {}

    public function put(string $reference, string $contents): self
    {
        $this->audio[$reference] = $contents;

        return $this;
    }

    /**
     * Behave like a provider whose result URL has expired.
     */
    public function failWith(): self
    {
        $this->failing = true;

        return $this;
    }

    public function recover(): self
    {
        $this->failing = false;

        return $this;
    }

    /**
     * @return resource
     */
    public function open(string $sourceReference)
    {
        if ($this->failing) {
            throw new RuntimeException(sprintf('The generated audio at [%s] could not be read.', $sourceReference));
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }

        fwrite($stream, $this->audio[$sourceReference] ?? $this->default);
        rewind($stream);

        return $stream;
    }
}
