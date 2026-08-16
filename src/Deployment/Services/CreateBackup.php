<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\Exceptions\BackupException;

/**
 * Taking a backup.
 *
 * A directory, not an archive. `ZipArchive` and `PharData` are both optional
 * PHP extensions and shared hosting is the baseline target, so a backup that
 * needed one would be a backup that sometimes cannot be taken. A directory of
 * files with a manifest beside them can be tarred by an operator who has tar
 * and copied by one who does not.
 *
 * **The manifest is written last.** Until it exists the directory is not a
 * backup, and a run interrupted halfway leaves something that
 * {@see BackupRepository} will not offer for restore and will not prune. That
 * ordering is the whole of the crash-safety story: there is no state in which
 * a partial backup looks complete.
 */
final readonly class CreateBackup
{
    public function __construct(
        private Connection $connection,
        private DatabaseDumper $dumper,
        private BackupRepository $repository,
        private Repository $config,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return array{path: string, manifest: BackupManifest}
     */
    public function handle(?string $label = null): array
    {
        $directory = $this->repository->prepareNew($label);

        /** @var list<string> $skip */
        $skip = (array) $this->config->get('backup.skip_table_contents', []);

        $entries = [];
        $entries[] = $this->writeDatabase($directory, $skip);

        foreach ($this->includedPaths() as $relative) {
            foreach ($this->copyTree($directory, $relative) as $entry) {
                $entries[] = $entry;
            }
        }

        $manifest = BackupManifest::describing(
            createdAt: Carbon::now()->toAtomString(),
            appVersion: (string) $this->config->get('app.version', 'unknown'),
            databaseDriver: (string) $this->connection->getDriverName(),
            entries: $entries,
            migrations: $this->repository->appliedMigrations(),
            excluded: $this->exclusions($skip),
        );

        // Last. Everything above can fail and leave a directory that is
        // visibly not a backup; nothing above can leave one that pretends.
        $this->repository->writeManifest($directory, $manifest);

        // After the manifest, so a line saying a backup was made only exists
        // once one does. The directory name is a label the operator chose plus
        // a timestamp — not a path, which would say where this installation
        // keeps its backups.
        $this->audit->record(
            AuditAction::BackupCreated,
            context: ['name' => basename($directory), 'entries' => count($entries)],
        );

        return ['path' => $directory, 'manifest' => $manifest];
    }

    /**
     * @param  list<string>  $skip
     * @return array{path: string, bytes: int, sha256: string}
     */
    private function writeDatabase(string $directory, array $skip): array
    {
        $relative = 'database.jsonl';
        $handle = fopen($directory.'/'.$relative, 'wb');

        if ($handle === false) {
            throw BackupException::destinationNotWritable($directory);
        }

        // Hashed as it is written rather than by re-reading the file. Reading
        // a multi-gigabyte dump back to digest it doubles the I/O of every
        // backup, and on a shared host the I/O is the budget.
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            foreach ($this->dumper->lines($skip) as $line) {
                $line .= "\n";
                hash_update($hash, $line);
                $bytes += (int) fwrite($handle, $line);
            }
        } finally {
            fclose($handle);
        }

        return ['path' => $relative, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    /**
     * @return list<array{path: string, bytes: int, sha256: string}>
     */
    private function copyTree(string $directory, string $relative): array
    {
        $source = base_path($relative);

        if (! is_dir($source)) {
            return [];
        }

        $entries = [];

        /** @var list<string> $files */
        $files = [];
        $this->collect($source, $files);
        sort($files);

        foreach ($files as $file) {
            $inside = 'files/'.$relative.'/'.ltrim(substr($file, strlen($source)), '/');
            $target = $directory.'/'.$inside;

            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0o750, true) && ! is_dir(dirname($target))) {
                throw BackupException::destinationNotWritable(dirname($target));
            }

            if (! copy($file, $target)) {
                throw BackupException::destinationNotWritable($target);
            }

            $entries[] = [
                'path' => $inside,
                'bytes' => (int) filesize($target),
                'sha256' => (string) hash_file('sha256', $target),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $into
     */
    private function collect(string $directory, array &$into): void
    {
        /** @var list<string> $found */
        $found = glob(rtrim($directory, '/').'/*') ?: [];

        foreach ($found as $path) {
            // Symlinks are not followed. `storage/app/public` is itself
            // reached through one, and a backup that followed links would
            // happily recurse into whatever an operator had linked in.
            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $this->collect($path, $into);

                continue;
            }

            $into[] = $path;
        }
    }

    /**
     * @return list<string>
     */
    private function includedPaths(): array
    {
        /** @var list<string> $paths */
        $paths = (array) $this->config->get('backup.include_paths', []);

        return array_values(array_filter($paths, static fn (string $path): bool => trim($path) !== ''));
    }

    /**
     * What was left out, and why.
     *
     * Recorded rather than assumed. An operator reading a manifest six months
     * from now should not have to know what the configuration was on the day
     * it was written.
     *
     * @param  list<string>  $skip
     * @return array<string, string>
     */
    private function exclusions(array $skip): array
    {
        $excluded = [];

        foreach ($skip as $table) {
            $excluded['table:'.$table] = 'Ephemeral. Restoring last week\'s rows would be worse than an empty table.';
        }

        $excluded['files:audio-masters'] = 'Audio masters are not copied into a backup. Several hundred are tens of '
            .'gigabytes, and copying them on every run is not a backup strategy. They belong in object storage or a '
            .'separate file-level backup — see docs/deployment/backup.md.';

        return $excluded;
    }
}
