<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

use Illuminate\Database\Schema\Builder;
use Throwable;

/**
 * Reads a schema description off a live connection.
 *
 * This is where every engine difference is absorbed, so the rules themselves
 * never have to ask which database they are looking at:
 *
 *   - MySQL and MariaDB report `varchar(191)`; SQLite reports `varchar`. When
 *     the engine gives a width it is used, and when it does not, the width
 *     declared in the migration is used instead — see `$declaredLengths`.
 *   - MySQL and MariaDB report a collation on every character column, whether
 *     it was declared or inherited from the table default. SQLite reports
 *     none. Both are passed through unchanged; deciding what that means is the
 *     inspector's job, not this class's.
 */
final readonly class LiveSchemaSource implements SchemaSource
{
    /**
     * @param  array<string, array<string, int>>  $declaredLengths  table => column => characters, for engines
     *                                                              that do not report a width of their own
     */
    public function __construct(
        private Builder $schema,
        private array $declaredLengths = [],
    ) {}

    public function tables(): array
    {
        return array_map(
            static fn (array $table): string => $table['name'],
            $this->schema->getTables(),
        );
    }

    public function indexes(string $table): array
    {
        $indexes = [];

        foreach ($this->schema->getIndexes($table) as $index) {
            $indexes[] = new TableIndex(name: $index['name'], columns: $index['columns']);
        }

        return $indexes;
    }

    public function columns(string $table): array
    {
        $columns = [];

        foreach ($this->schema->getColumns($table) as $column) {
            $columns[] = new TableColumn(
                name: $column['name'],
                typeName: strtolower($column['type_name']),
                length: $this->length($table, $column['name'], $column['type']),
                collation: $this->undeclaredString($column, 'collation'),
            );
        }

        return $columns;
    }

    public function foreignKeyNames(string $table): array
    {
        $names = [];

        try {
            $foreignKeys = $this->schema->getForeignKeys($table);
        } catch (Throwable) {
            // Not every driver reports constraint names. The engines that
            // enforce the identifier limit all do, so silence here costs no
            // coverage where it matters.
            return [];
        }

        foreach ($foreignKeys as $foreignKey) {
            $name = $this->undeclaredString($foreignKey, 'name');

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Reads a key the framework's documented array shape does not mention.
     *
     * `getColumns()` is documented without `collation`, yet the MySQL and
     * MariaDB implementations return one on every character column. Rather
     * than assert a shape the framework does not promise, the row is read as
     * the plain array it is: absent on engines that do not report the key,
     * present on the ones that do.
     *
     * @param  array<string, mixed>  $row
     */
    private function undeclaredString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The width the engine reports, or the one the migration declared.
     */
    private function length(string $table, string $column, string $type): ?int
    {
        if (preg_match('/^(?:var)?char\s*\((\d+)\)/i', $type, $matches) === 1) {
            return (int) $matches[1];
        }

        return $this->declaredLengths[$table][$column] ?? null;
    }
}
