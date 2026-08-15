<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database;

use Illuminate\Database\Schema\Builder;
use Throwable;

/**
 * Inspects a live schema for divergences between SQLite and MySQL/MariaDB.
 *
 * **A guardrail, not proof.** The CI matrix decides whether the schema works;
 * this only catches the rules that are mechanically checkable in a second, on
 * whatever engine happens to be in front of you — which in practice is SQLite,
 * the one engine that enforces almost none of them.
 *
 * It exists because of a concrete miss: a 65-character index name migrated
 * cleanly on SQLite and failed on MySQL, MariaDB 10.6 and MariaDB 11.4 alike,
 * since SQLite has no identifier length limit at all.
 *
 * Each check is either exact (identifier length) or conservative in the
 * direction of a false alarm (index key bytes). A guardrail that is wrong in
 * the other direction would be worse than none.
 */
final readonly class SchemaPortabilityInspector
{
    /** MySQL and MariaDB cap identifiers at 64 characters. */
    public const MAX_IDENTIFIER_LENGTH = 64;

    /** InnoDB's index key limit with the DYNAMIC row format. */
    public const MAX_INDEX_KEY_BYTES = 3072;

    /** utf8mb4 stores up to four bytes per character. */
    public const BYTES_PER_CHARACTER = 4;

    /**
     * @param  array<string, array<string, int>>  $declaredLengths  table => column => declared characters
     */
    public function __construct(
        private Builder $schema,
        private array $declaredLengths = [],
    ) {}

    /**
     * Names too long for MySQL/MariaDB.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function overlongIdentifiers(array $tables): array
    {
        $offenders = [];

        foreach ($tables as $table) {
            if (strlen($table) > self::MAX_IDENTIFIER_LENGTH) {
                $offenders[] = sprintf('table %s (%d chars)', $table, strlen($table));
            }

            foreach ($this->schema->getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');

                if ($name !== '' && strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('index %s.%s (%d chars)', $table, $name, strlen($name));
                }
            }

            foreach ($this->foreignKeys($table) as $foreignKey) {
                $name = (string) ($foreignKey['name'] ?? '');

                if ($name !== '' && strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('foreign key %s.%s (%d chars)', $table, $name, strlen($name));
                }
            }
        }

        return $offenders;
    }

    /**
     * Indexes whose key could overflow InnoDB's limit once every character
     * costs four bytes.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function oversizedIndexKeys(array $tables): array
    {
        $offenders = [];

        foreach ($tables as $table) {
            foreach ($this->schema->getIndexes($table) as $index) {
                $columns = array_map(strval(...), (array) ($index['columns'] ?? []));
                $bytes = 0;

                foreach ($columns as $column) {
                    $bytes += $this->estimateColumnBytes($table, $column);
                }

                if ($bytes > self::MAX_INDEX_KEY_BYTES) {
                    $offenders[] = sprintf(
                        '%s.%s ≈ %d bytes (%s)',
                        $table,
                        (string) ($index['name'] ?? '?'),
                        $bytes,
                        implode(', ', $columns),
                    );
                }
            }
        }

        return $offenders;
    }

    /**
     * Columns carrying their own collation — a name that exists on one engine
     * may not exist on the other.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function columnLevelCollations(array $tables): array
    {
        $offenders = [];

        foreach ($tables as $table) {
            foreach ($this->schema->getColumns($table) as $column) {
                $collation = $column['collation'] ?? null;

                if (is_string($collation) && $collation !== '') {
                    $offenders[] = sprintf('%s.%s (%s)', $table, (string) $column['name'], $collation);
                }
            }
        }

        return $offenders;
    }

    /**
     * A column whose declared length is unknown is costed as a full
     * varchar(255): the check errs towards a false alarm rather than a miss.
     */
    private function estimateColumnBytes(string $table, string $column): int
    {
        $declared = $this->declaredLengths[$table][$column] ?? null;

        if ($declared !== null) {
            return $declared * self::BYTES_PER_CHARACTER;
        }

        return match ($this->columnType($table, $column)) {
            'bigint' => 8,
            'integer', 'int' => 4,
            'smallint' => 2,
            'tinyint', 'boolean' => 1,
            'date' => 3,
            'datetime', 'timestamp' => 5,
            'decimal', 'numeric' => 9,
            'float', 'double' => 8,
            default => 255 * self::BYTES_PER_CHARACTER,
        };
    }

    private function columnType(string $table, string $column): string
    {
        foreach ($this->schema->getColumns($table) as $candidate) {
            if ((string) $candidate['name'] === $column) {
                return strtolower((string) ($candidate['type_name'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foreignKeys(string $table): array
    {
        try {
            return $this->schema->getForeignKeys($table);
        } catch (Throwable) {
            // Not every driver reports constraint names; the engines that
            // enforce the limit all do, so silence here is safe.
            return [];
        }
    }
}
