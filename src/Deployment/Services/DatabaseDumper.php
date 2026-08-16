<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Generator;
use Illuminate\Database\Connection;

/**
 * The database, as rows rather than as SQL.
 *
 * **No `mysqldump`.** Shared hosting frequently has no shell, no `exec()` and
 * no client binaries, and a backup command that needs them is a backup command
 * that does not run on the platform's baseline target. Everything here goes
 * through the connection the application already has.
 *
 * The format is JSON Lines, and that is a portability decision rather than a
 * stylistic one. A SQL dump is written in one engine's dialect: MySQL's
 * `INSERT` quoting, SQLite's type affinity and MariaDB's reserved words all
 * differ, and a dump taken on one is not restorable on another. SaniTube runs
 * on four engines and a label may well move between them — a shared host to a
 * VPS is exactly that move — so rows travel as data and the *schema* comes
 * from migrations, which are already engine-neutral.
 *
 * Rows are streamed in chunks and yielded, never collected. A catalogue of a
 * few hundred thousand rows must not have to fit in the memory limit of a
 * shared host.
 *
 * Not `final` and not a `readonly` class — the property is readonly instead.
 * Anonymous `readonly` classes need PHP 8.3 and the matrix starts at 8.2, so a
 * readonly class here would be one that cannot be substituted on the oldest
 * supported runtime. Deliberately substitutable, because: {@see CreateBackup}'s crash-safety claim — that
 * a run which dies halfway leaves nothing that looks complete — can only be
 * proved by a dump that actually fails partway through. A substitutable
 * collaborator is the cheapest honest way to stage that.
 */
class DatabaseDumper
{
    /** Rows read per query. Small enough for a 128MB shared host. */
    private const CHUNK = 500;

    public function __construct(private readonly Connection $connection) {}

    /**
     * Every table this connection has, in an order a restore can replay.
     *
     * Two properties, and the second was learned the hard way.
     *
     * **Stable**, so two backups of the same database produce byte-identical
     * dumps — which is what makes a checksum mean anything, and what lets an
     * operator see at a glance that nothing changed.
     *
     * **Dependency-ordered**, so a table is written after everything it points
     * at. A dump sorted alphabetically restores `distribution_attempts` before
     * the `distribution_deliveries` they belong to, and every engine that
     * enforces foreign keys refuses the insert. Turning the constraints off
     * for the restore is the usual answer and is *also* done — but SQLite
     * ignores `PRAGMA foreign_keys` inside a transaction, so a restore that
     * relied on it alone would be one nobody could perform on the platform's
     * smallest supported database. Writing the file in an order that does not
     * need the escape hatch is the fix; the escape hatch stays for cycles.
     *
     * Alphabetical within each dependency level, so the order is still
     * deterministic and a checksum still means something.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->connection->getSchemaBuilder()->getTables() as $table) {
            /** @var array{name: string} $table */
            $tables[] = $table['name'];
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $this->inDependencyOrder($tables);
    }

    /**
     * Kahn's algorithm, alphabetical among the ready set.
     *
     * A table whose dependencies cannot be resolved — a cycle, or a schema
     * this connection will not describe — is appended at the end rather than
     * dropped. A dump missing a table is a backup that silently loses data,
     * which is a far worse failure than one whose restore needs the foreign
     * keys switched off.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function inDependencyOrder(array $tables): array
    {
        $known = array_flip($tables);
        $depends = [];

        foreach ($tables as $table) {
            $depends[$table] = [];

            foreach ($this->foreignKeysOf($table) as $foreign) {
                // A self-reference is not a dependency between tables; it is
                // satisfied row by row and never by ordering.
                if ($foreign !== $table && array_key_exists($foreign, $known)) {
                    $depends[$table][$foreign] = true;
                }
            }
        }

        $ordered = [];
        $placed = [];

        while (count($ordered) < count($tables)) {
            $ready = [];

            foreach ($tables as $table) {
                if (array_key_exists($table, $placed)) {
                    continue;
                }

                if (array_diff_key($depends[$table], $placed) === []) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                foreach ($tables as $table) {
                    if (! array_key_exists($table, $placed)) {
                        $ordered[] = $table;
                    }
                }

                return $ordered;
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                $placed[$table] = true;
            }
        }

        return $ordered;
    }

    /**
     * The tables this one points at.
     *
     * @return list<string>
     */
    private function foreignKeysOf(string $table): array
    {
        $foreign = [];

        foreach ($this->connection->getSchemaBuilder()->getForeignKeys($table) as $key) {
            /** @var array{foreign_table?: string|null} $key */
            $referenced = $key['foreign_table'] ?? null;

            if (is_string($referenced) && $referenced !== '') {
                $foreign[] = $referenced;
            }
        }

        return array_values(array_unique($foreign));
    }

    /**
     * The dump, one JSON object per line.
     *
     * A table header precedes its rows, so a reader always knows what it is
     * looking at without holding the whole file. A table with no rows still
     * emits its header: "this table was backed up and was empty" and "this
     * table was not backed up" are different facts.
     *
     * @param  list<string>  $skipContentsOf
     * @return Generator<int, string>
     */
    public function lines(array $skipContentsOf = []): Generator
    {
        foreach ($this->tables() as $table) {
            $skipped = in_array($table, $skipContentsOf, true);

            yield $this->encode(['_table' => $table, '_skipped' => $skipped]);

            if ($skipped) {
                continue;
            }

            foreach ($this->rows($table) as $row) {
                yield $this->encode(['_row' => $row]);
            }
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(string $table): Generator
    {
        $offset = 0;

        // Ordered by the primary key when there is one, so the dump is stable
        // between runs. Without an order, two engines — and sometimes one
        // engine twice — return the same rows in different sequences, and a
        // checksum over that is noise.
        $orderBy = $this->orderColumn($table);

        while (true) {
            $query = $this->connection->table($table);

            if ($orderBy !== null) {
                $query->orderBy($orderBy);
            }

            $rows = $query->offset($offset)->limit(self::CHUNK)->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                yield (array) $row;
            }

            $offset += self::CHUNK;
        }
    }

    private function orderColumn(string $table): ?string
    {
        $columns = $this->connection->getSchemaBuilder()->getColumnListing($table);

        foreach (['id', 'uuid'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $columns[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        // Unescaped slashes and unicode so the file is readable and, more
        // usefully, so its size reflects the data rather than the escaping.
        // Invalid UTF-8 is substituted rather than thrown on: a single legacy
        // byte in one imported filename must not be what stops a backup.
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
