<?php

declare(strict_types=1);

namespace SaniTube\Releases\Packaging;

/**
 * One credited artist, with the role that decides how a store bills it.
 */
final readonly class PackagedArtist
{
    public function __construct(
        public string $name,
        public string $role,
        public int $position,
        public ?string $isni = null,
    ) {}
}
