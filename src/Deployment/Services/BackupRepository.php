<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\Exceptions\BackupException;

/**
 * Where backups live, which of them are real, and which may be deleted.
 *
 * Separated from taking and restoring them because *deleting* is the operation
 * that needs the most care and the least ceremony elsewhere. Pruning is the
 * only routine, automatic, destructive act in the platform, and it runs
 * unattended.
 *
 * Two rules make it safe:
 *
 *   - **Only complete backups are prunable.** A directory with no manifest is
 *     either a run in progress or the evidence of a failure. Both are things an
 *     operator should find, not have tidied away.
 *   - **The newest is never pruned**, whatever `keep` says. A `keep` of zero
 *     from a mistyped environment variable must not mean "delete everything the
 *     moment the next backup succeeds".
 */
final readonly class BackupRepository
{
    private const MANIFEST = 'manifest.json';

    public function __construct(private Connection $connection, private Repository $config) {}

    /**
     * The configured destination, checked rather than trusted.
     */
    public function destination(): string
    {
        $path = rtrim((string) $this->config->get('backup.destination', storage_path('backups')), '/');

        // A backup holds every row of the catalogue and every credential-shaped
        // value in the database. One inside the web root is worse than no
        // backup at all, and this is checked on every call because the check
        // costs nothing and the mistake costs everything.
        $public = rtrim(public_path(), '/');

        if ($path === $public || str_starts_with($path.'/', $public.'/')) {
            throw BackupException::destinationIsPublic($path);
        }

        return $path;
    }

    /**
     * A new, empty backup directory.
     *
     * Named by timestamp so the ordering on disk is the ordering in time, with
     * a suffix when two land in the same second — a scheduled run and a manual
     * one, which is exactly when it happens.
     */
    public function prepareNew(?string $label = null): string
    {
        $destination = $this->destination();

        if (! is_dir($destination) && ! mkdir($destination, 0o750, true) && ! is_dir($destination)) {
            throw BackupException::destinationNotWritable($destination);
        }

        $base = Carbon::now()->format('Y-m-d_His');

        if ($label !== null && trim($label) !== '') {
            $base .= '_'.preg_replace('/[^A-Za-z0-9_-]/', '-', trim($label));
        }

        $directory = $destination.'/'.$base;
        $suffix = 1;

        while (is_dir($directory)) {
            $directory = $destination.'/'.$base.'-'.$suffix++;
        }

        if (! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw BackupException::destinationNotWritable($directory);
        }

        return $directory;
    }

    public function writeManifest(string $directory, BackupManifest $manifest): void
    {
        $encoded = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($directory.'/'.self::MANIFEST, $encoded) === false) {
            throw BackupException::destinationNotWritable($directory);
        }

        // 0600: the manifest lists every table in the installation. Nothing
        // secret, and nothing a neighbouring account on a shared host needs.
        chmod($directory.'/'.self::MANIFEST, 0o600);
    }

    public function manifestAt(string $directory): BackupManifest
    {
        $path = rtrim($directory, '/').'/'.self::MANIFEST;

        if (! is_file($path)) {
            throw BackupException::notABackup($directory);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw BackupException::manifestUnreadable('it is not valid JSON');
        }

        /** @var array<string, mixed> $decoded */
        return BackupManifest::fromArray($decoded);
    }

    /**
     * Complete backups, newest first.
     *
     * A directory without a manifest is not listed. It is not a backup, and
     * offering one for restore is offering to write half a database.
     *
     * @return list<array{path: string, manifest: BackupManifest}>
     */
    public function complete(): array
    {
        $destination = $this->destination();

        if (! is_dir($destination)) {
            return [];
        }

        $found = [];

        /** @var list<string> $candidates */
        $candidates = glob($destination.'/*', GLOB_ONLYDIR) ?: [];

        foreach ($candidates as $directory) {
            if (! is_file($directory.'/'.self::MANIFEST)) {
                continue;
            }

            try {
                $found[] = ['path' => $directory, 'manifest' => $this->manifestAt($directory)];
            } catch (BackupException) {
                // An unreadable manifest is not a complete backup either, and
                // it is emphatically not something to delete on the way past.
                continue;
            }
        }

        usort($found, static fn (array $a, array $b): int => strcmp($b['path'], $a['path']));

        return $found;
    }

    /**
     * Remove the oldest complete backups beyond `keep`.
     *
     * @return list<string> the directories removed
     */
    public function prune(?int $keep = null): array
    {
        $keep = $keep ?? (int) $this->config->get('backup.keep', 7);

        // Never zero. A mistyped environment variable must not mean "delete
        // everything the moment the next backup succeeds".
        $keep = max(1, $keep);

        $complete = $this->complete();

        if (count($complete) <= $keep) {
            return [];
        }

        $removed = [];

        foreach (array_slice($complete, $keep) as $backup) {
            $this->deleteTree($backup['path']);
            $removed[] = $backup['path'];
        }

        return $removed;
    }

    /**
     * Which migrations this database has run.
     *
     * @return list<string>
     */
    public function appliedMigrations(): array
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('migrations')) {
            return [];
        }

        /** @var list<string> $names */
        $names = $this->connection->table('migrations')->orderBy('migration')->pluck('migration')->all();

        return $names;
    }

    /**
     * Delete a backup directory and nothing outside it.
     *
     * `realpath` before anything is unlinked: a symlink placed inside a backup
     * directory is the one way a recursive delete reaches the rest of the
     * disk, and this is the only recursive delete in the platform.
     */
    private function deleteTree(string $directory): void
    {
        $root = realpath($this->destination());
        $target = realpath($directory);

        if ($root === false || $target === false || ! str_starts_with($target.'/', $root.'/')) {
            return;
        }

        // `scandir`, not `glob`. Glob does not match a leading dot, and a
        // backup can contain hidden files — so a glob-based delete empties a
        // directory of everything it can see, then fails on `rmdir` because
        // of what it could not. Pruning would then leave every backup it tried
        // to remove half-deleted, for ever, which is the opposite of what
        // pruning is for.
        /** @var list<string> $names */
        $names = scandir($target) ?: [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entry = $target.'/'.$name;

            if (is_link($entry) || is_file($entry)) {
                unlink($entry);

                continue;
            }

            if (is_dir($entry)) {
                $this->deleteTree($entry);
            }
        }

        rmdir($target);
    }
}
