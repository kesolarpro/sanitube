<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

/**
 * One credited person on a packaged track.
 */
final readonly class PackagedContributor
{
    public function __construct(
        public string $name,
        public string $role,
        public ?string $ipi = null,
        public ?string $isni = null,
    ) {}
}
