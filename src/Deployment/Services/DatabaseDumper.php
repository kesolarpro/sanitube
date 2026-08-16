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
     * Every table this connection has, in a stable order.
     *
     * Sorted so two backups of the same database produce byte-identical dumps
     * — which is what makes a checksum mean anything, and what lets an
     * operator see at a glance that nothing changed.
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

        sort($tables);

        return array_values(array_unique($tables));
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
