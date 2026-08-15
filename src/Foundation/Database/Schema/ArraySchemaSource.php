<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

/**
 * A schema written by hand.
 *
 * Its only purpose is to describe schemas no engine would accept, so that the
 * rules meant to reject them can be shown rejecting them. A 65-character index
 * name and a 3200-byte index key exist here and nowhere else — MySQL and
 * MariaDB refuse to create either, which is the entire point of checking for
 * them before a migration reaches one.
 */
final readonly class ArraySchemaSource implements SchemaSource
{
    /**
     * @param  array<string, array{columns?: list<TableColumn>, indexes?: list<TableIndex>, foreign_keys?: list<string>}>  $tables
     */
    public function __construct(private array $tables) {}

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function indexes(string $table): array
    {
        return $this->tables[$table]['indexes'] ?? [];
    }

    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'] ?? [];
    }

    public function foreignKeyNames(string $table): array
    {
        return $this->tables[$table]['foreign_keys'] ?? [];
    }
}
