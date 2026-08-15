<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

/**
 * One column, described in the terms the portability rules actually need.
 *
 * `$length` is nullable on purpose. SQLite reports `varchar` with no length at
 * all, so on the one engine most likely to be in front of a developer the
 * declared width is simply unknown — and a check that pretends otherwise would
 * be guessing.
 */
final readonly class TableColumn
{
    public function __construct(
        public string $name,
        public string $typeName,
        public ?int $length = null,
        public ?string $collation = null,
    ) {}
}
