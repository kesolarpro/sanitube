<?php

declare(strict_types=1);

namespace Tests\Support\Media;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Answers where a binary lives without looking, so the parser can be tested on
 * a machine that does not have Chromaprint installed — which is most of them.
 */
final class StubExecutableFinder extends ExecutableFinder
{
    public function __construct(private readonly ?string $path) {}

    /**
     * @param  array<array-key, string>  $extraDirs
     */
    public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
    {
        return $this->path;
    }
}
