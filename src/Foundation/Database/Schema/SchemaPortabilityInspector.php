<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database\Schema;

/**
 * Checks a schema description against the rules MySQL and MariaDB enforce and
 * SQLite does not.
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
 * Each rule is either exact (identifier length) or conservative in the
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

    /** What an undeclared string column is assumed to cost. */
    public const ASSUMED_STRING_LENGTH = 255;

    /**
     * @param  string|null  $expectedCollation  the collation the connection is configured with; null when the
     *                                          engine has no concept of one, which makes the collation rule
     *                                          inapplicable rather than satisfied
     */
    public function __construct(
        private SchemaSource $schema,
        private ?string $expectedCollation = null,
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

            foreach ($this->schema->indexes($table) as $index) {
                if (strlen($index->name) > self::MAX_IDENTIFIER_LENGTH) {
                    $offenders[] = sprintf('index %s.%s (%d chars)', $table, $index->name, strlen($index->name));
                }
            }

            foreach ($this->schema->foreignKeyNames($table) as $name) {
                if (strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
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
            $columns = $this->columnsByName($table);

            foreach ($this->schema->indexes($table) as $index) {
                $bytes = 0;

                foreach ($index->columns as $column) {
                    $bytes += $this->estimateBytes($columns[$column] ?? null);
                }

                if ($bytes > self::MAX_INDEX_KEY_BYTES) {
                    $offenders[] = sprintf(
                        '%s.%s ≈ %d bytes (%s)',
                        $table,
                        $index->name,
                        $bytes,
                        implode(', ', $index->columns),
                    );
                }
            }
        }

        return $offenders;
    }

    /**
     * Columns whose collation differs from the one the connection is
     * configured with.
     *
     * The rule is deliberately *divergence*, not presence. A live schema
     * cannot answer "was this collation declared explicitly?" — MySQL and
     * MariaDB report a collation on every character column, inherited ones
     * included, and that information is lost at CREATE TABLE time. What can be
     * answered, and is the part that actually breaks a restore, is whether a
     * column carries a collation the rest of the installation does not.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function divergentCollations(array $tables): array
    {
        if ($this->expectedCollation === null) {
            return [];
        }

        $offenders = [];

        foreach ($tables as $table) {
            foreach ($this->schema->columns($table) as $column) {
                if ($column->collation !== null && $column->collation !== $this->expectedCollation) {
                    $offenders[] = sprintf(
                        '%s.%s is %s, expected %s',
                        $table,
                        $column->name,
                        $column->collation,
                        $this->expectedCollation,
                    );
                }
            }
        }

        return $offenders;
    }

    /**
     * A column of unknown width is costed as a full varchar(255): the check
     * errs towards a false alarm rather than a miss.
     */
    private function estimateBytes(?TableColumn $column): int
    {
        if ($column === null) {
            return self::ASSUMED_STRING_LENGTH * self::BYTES_PER_CHARACTER;
        }

        if ($column->length !== null) {
            return $column->length * self::BYTES_PER_CHARACTER;
        }

        return match ($column->typeName) {
            'bigint' => 8,
            'integer', 'int' => 4,
            'mediumint' => 3,
            'smallint' => 2,
            'tinyint', 'boolean', 'bool' => 1,
            'date' => 3,
            'datetime', 'timestamp' => 5,
            'decimal', 'numeric' => 9,
            'float', 'double' => 8,
            default => self::ASSUMED_STRING_LENGTH * self::BYTES_PER_CHARACTER,
        };
    }

    /**
     * @return array<string, TableColumn>
     */
    private function columnsByName(string $table): array
    {
        $columns = [];

        foreach ($this->schema->columns($table) as $column) {
            $columns[$column->name] = $column;
        }

        return $columns;
    }
}
