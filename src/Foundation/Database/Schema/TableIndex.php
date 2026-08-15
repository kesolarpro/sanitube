<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

final readonly class TableIndex
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {}
}
