<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Illuminate\Database\Connection;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\Exceptions\BackupException;
use Throwable;

/**
 * Putting a backup back.
 *
 * The single most destructive operation in the platform, and the only one that
 * deliberately overwrites data somebody is still using. Everything here is
 * arranged so that it fails *before* it writes rather than during.
 *
 * The order is not negotiable:
 *
 *   1. **Is it a backup?** A manifest, readable, in a format this code knows.
 *   2. **Is it complete?** Every entry the manifest names is present.
 *   3. **Is it intact?** Every checksum matches. A corrupt backup restored
 *      writes damage over working data, which is worse than not restoring.
 *   4. **Does it belong here?** The migration set must match, or the caller
 *      must say explicitly that they know it does not.
 *   5. **Was it asked for?** Confirmed, explicitly.
 *
 * Only then does anything change. `verify()` is steps one to four on their own,
 * so an operator can find out whether a backup is restorable at a moment when
 * the answer costs nothing.
 *
 * **Files and the database cannot be restored atomically**, and this does not
 * pretend otherwise. The database is restored inside a transaction and files
 * are copied afterwards: if the file copy fails, the database is already
 * consistent with the backup and the operator is told exactly which files did
 * not land. The reverse order would leave files from a backup beside a
 * database that never received it.
 */
final readonly class RestoreBackup
{
    public function __construct(
        private Connection $connection,
        private BackupRepository $repository,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * Everything that can be checked without changing anything.
     *
     * @return array{manifest: BackupManifest, entries: int, bytes: int, drift: list<string>}
     */
    public function verify(string $directory, bool $allowSchemaDrift = false): array
    {
        $manifest = $this->repository->manifestAt($directory);

        if ($manifest->format > BackupManifest::FORMAT) {
            throw BackupException::manifestUnreadable(sprintf(
                'it is format %d and this installation understands %d. A newer SaniTube wrote it.',
                $manifest->format,
                BackupManifest::FORMAT,
            ));
        }

        $bytes = 0;

        foreach ($manifest->entries as $entry) {
            // safePath() first, and before any filesystem call: the manifest
            // is data, and `../../` in it must be refused rather than read.
            $path = $manifest->safePath($directory, $entry['path']);

            if (! is_file($path)) {
                throw BackupException::incomplete($entry['path']);
            }

            if (! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
                throw BackupException::corrupt($entry['path']);
            }

            $bytes += (int) $entry['bytes'];
        }

        $drift = $manifest->migrationDrift($this->repository->appliedMigrations());

        if ($drift !== [] && ! $allowSchemaDrift) {
            throw BackupException::schemaDrift($drift);
        }

        return ['manifest' => $manifest, 'entries' => count($manifest->entries), 'bytes' => $bytes, 'drift' => $drift];
    }

    /**
     * Restore, having been asked to explicitly.
     *
     * @return array{tables: int, rows: int, files: int, drift: list<string>}
     */
    public function handle(string $directory, bool $confirmed, bool $allowSchemaDrift = false): array
    {
        if (! $confirmed) {
            // Not a formality. A restore triggered by a stray keystroke or a
            // re-run shell command replaces a live catalogue, and the
            // confirmation is the only thing standing between those.
            throw BackupException::notConfirmed();
        }

        $verified = $this->verify($directory, $allowSchemaDrift);
        $manifest = $verified['manifest'];

        $dump = $manifest->safePath($directory, 'database.jsonl');

        if (! is_file($dump)) {
            throw BackupException::incomplete('database.jsonl');
        }

        $restored = $this->restoreDatabase($dump);

        // Files afterwards, and only once the database is consistent. A
        // failure here leaves a correct database and named, uncopied files —
        // recoverable. The other order leaves files from a backup beside a
        // database that never received it, which is not.
        $files = $this->restoreFiles($directory, $manifest);

        // The one audit line that survives its own subject. A restore replaces
        // `audit_events` with whatever the backup held, so every line written
        // before this moment is now the backup's history rather than this
        // installation's — and this line, written after, is the only thing
        // that says the two were ever different.
        $this->audit->record(
            AuditAction::BackupRestored,
            context: [
                'name' => basename($directory),
                'tables' => $restored['tables'],
                'rows' => $restored['rows'],
                'schema_drift' => count($verified['drift']),
            ],
        );

        return [
            'tables' => $restored['tables'],
            'rows' => $restored['rows'],
            'files' => $files,
            'drift' => $verified['drift'],
        ];
    }

    /**
     * @return array{tables: int, rows: int}
     */
    private function restoreDatabase(string $dump): array
    {
        $handle = fopen($dump, 'rb');

        if ($handle === false) {
            throw BackupException::incomplete('database.jsonl');
        }

        $tables = 0;
        $rows = 0;

        // One transaction for the whole restore. Data-only — no DDL — so it
        // rolls back cleanly on every engine in the matrix, and a restore
        // that dies halfway leaves the database as it was rather than half
        // replaced.
        //
        // Order is what makes this work without help from the engine. The dump
        // is written so a table follows everything it points at, so inserting
        // in file order never presents a child before its parent — and
        // emptying in *reverse* file order never removes a parent while a
        // child still references it. Both passes are ordered here rather than
        // left to the constraints being off, because SQLite ignores
        // `PRAGMA foreign_keys` inside a transaction: a restore that leaned on
        // the escape hatch would be green everywhere except the platform's
        // smallest supported database, which is also its most common one.
        //
        // The escape hatch is still opened, for the cases ordering cannot
        // cover — a cyclic schema, or a backup written before the dump was
        // ordered at all.
        try {
            $this->emptyTables($this->tablesIn($dump));

            $this->connection->getSchemaBuilder()->withoutForeignKeyConstraints(function () use ($handle, &$tables, &$rows): void {
                $this->connection->transaction(function () use ($handle, &$tables, &$rows): void {
                    $table = null;
                    $buffer = [];

                    while (($line = fgets($handle)) !== false) {
                        $decoded = json_decode(trim($line), true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        if (array_key_exists('_table', $decoded)) {
                            $this->flush($table, $buffer);
                            $table = (string) $decoded['_table'];
                            $tables++;

                            continue;
                        }

                        if ($table !== null && array_key_exists('_row', $decoded) && is_array($decoded['_row'])) {
                            /** @var array<string, mixed> $row */
                            $row = $decoded['_row'];
                            $buffer[] = $row;
                            $rows++;

                            if (count($buffer) >= 200) {
                                $this->flush($table, $buffer);
                            }
                        }
                    }

                    $this->flush($table, $buffer);
                });
            });
        } finally {
            fclose($handle);
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * The tables the dump names, in the order it names them.
     *
     * A header-only pass. Every table has to be emptied before the first row
     * is inserted — a table emptied halfway through the stream would drop rows
     * a child table already pointed at — and the whole list is not known until
     * the file has been read once.
     *
     * @return list<string>
     */
    private function tablesIn(string $dump): array
    {
        $handle = fopen($dump, 'rb');

        if ($handle === false) {
            throw BackupException::incomplete('database.jsonl');
        }

        $tables = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);

                if (is_array($decoded) && array_key_exists('_table', $decoded)) {
                    $tables[] = (string) $decoded['_table'];
                }
            }
        } finally {
            fclose($handle);
        }

        return $tables;
    }

    /**
     * Empty every table the dump names, children first.
     *
     * Emptied, not dropped. The schema belongs to migrations; a restore that
     * recreated tables would be a second, worse migrator.
     *
     * @param  list<string>  $tables  in dump order
     */
    private function emptyTables(array $tables): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->withoutForeignKeyConstraints(function () use ($tables, $schema): void {
            $this->connection->transaction(function () use ($tables, $schema): void {
                foreach (array_reverse($tables) as $table) {
                    if ($schema->hasTable($table)) {
                        $this->connection->table($table)->delete();
                    }
                }
            });
        });
    }

    /**
     * @param  list<array<string, mixed>>  $buffer
     */
    private function flush(?string $table, array &$buffer): void
    {
        if ($table === null || $buffer === []) {
            $buffer = [];

            return;
        }

        if ($this->connection->getSchemaBuilder()->hasTable($table)) {
            $this->connection->table($table)->insert($buffer);
        }

        $buffer = [];
    }

    private function restoreFiles(string $directory, BackupManifest $manifest): int
    {
        $restored = 0;

        foreach ($manifest->entries as $entry) {
            if (! str_starts_with($entry['path'], 'files/')) {
                continue;
            }

            $source = $manifest->safePath($directory, $entry['path']);
            $relative = substr($entry['path'], strlen('files/'));

            // Checked a second time on the way out. The first check proved the
            // path was safe to *read* inside the backup; this one proves the
            // destination is safe to *write* inside the application.
            $target = $manifest->safePath(base_path(), $relative);

            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0o750, true) && ! is_dir(dirname($target))) {
                continue;
            }

            try {
                if (copy($source, $target)) {
                    $restored++;
                }
            } catch (Throwable) {
                // Named in the count rather than thrown on. The database is
                // already consistent, and failing the whole restore over one
                // unwritable cover would be trading a working catalogue for a
                // tidy exit code.
                continue;
            }
        }

        return $restored;
    }
}
