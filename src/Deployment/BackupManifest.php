<?php

declare(strict_types=1);

namespace SaniTube\Deployment;

use SaniTube\Deployment\Exceptions\BackupException;

/**
 * What a backup contains, and what it deliberately does not.
 *
 * The manifest is the backup. Everything a restore decides — whether the
 * archive is complete, whether it is intact, whether it belongs to this
 * schema — is decided from here, and the files beside it are only bytes until
 * this says what they should be.
 *
 * Three things it records that a naive manifest would not:
 *
 *   - **`migrations`** — the exact migration set the database was on. Restoring
 *     rows from an older schema into a newer one silently is how a catalogue is
 *     corrupted, and a list of names is what makes that detectable.
 *   - **`excluded`** — what was left out, and why. A backup that quietly omits
 *     something is a backup nobody can reason about, and the omission is
 *     discovered at exactly the wrong moment.
 *   - **A checksum per entry.** Not one over the whole archive: a per-entry
 *     digest says *which* file is damaged, and a restore that can name the
 *     broken file is one somebody can act on.
 */
final readonly class BackupManifest
{
    /** Bumped when the on-disk layout changes in a way a reader must know about. */
    public const FORMAT = 1;

    /**
     * @param  list<array{path: string, bytes: int, sha256: string}>  $entries
     * @param  list<string>  $migrations
     * @param  array<string, string>  $excluded
     */
    public function __construct(
        public int $format,
        public string $createdAt,
        public string $appVersion,
        public string $databaseDriver,
        public array $entries,
        public array $migrations,
        public array $excluded,
    ) {}

    /**
     * @param  list<array{path: string, bytes: int, sha256: string}>  $entries
     * @param  list<string>  $migrations
     * @param  array<string, string>  $excluded
     */
    public static function describing(
        string $createdAt,
        string $appVersion,
        string $databaseDriver,
        array $entries,
        array $migrations,
        array $excluded,
    ): self {
        return new self(self::FORMAT, $createdAt, $appVersion, $databaseDriver, $entries, $migrations, $excluded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromArray(array $decoded): self
    {
        foreach (['format', 'created_at', 'entries', 'migrations'] as $required) {
            if (! array_key_exists($required, $decoded)) {
                throw BackupException::manifestUnreadable('missing ['.$required.']');
            }
        }

        /** @var list<array{path: string, bytes: int, sha256: string}> $entries */
        $entries = $decoded['entries'];
        /** @var list<string> $migrations */
        $migrations = $decoded['migrations'];
        /** @var array<string, string> $excluded */
        $excluded = is_array($decoded['excluded'] ?? null) ? $decoded['excluded'] : [];

        return new self(
            format: (int) $decoded['format'],
            createdAt: (string) $decoded['created_at'],
            appVersion: (string) ($decoded['app_version'] ?? 'unknown'),
            databaseDriver: (string) ($decoded['database_driver'] ?? 'unknown'),
            entries: $entries,
            migrations: $migrations,
            excluded: $excluded,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'created_at' => $this->createdAt,
            'app_version' => $this->appVersion,
            'database_driver' => $this->databaseDriver,
            'entries' => $this->entries,
            'migrations' => $this->migrations,
            'excluded' => $this->excluded,
        ];
    }

    /**
     * Migrations this backup does not know about.
     *
     * Deliberately one-directional. A backup that is *behind* the database is
     * the dangerous case — its rows predate columns that now exist — and it is
     * the case this reports. A backup that is *ahead* is reported too, because
     * either way the two disagree and somebody should decide rather than
     * discover.
     *
     * @param  list<string>  $current
     * @return list<string>
     */
    public function migrationDrift(array $current): array
    {
        $drift = array_merge(
            array_values(array_diff($current, $this->migrations)),
            array_values(array_diff($this->migrations, $current)),
        );

        sort($drift);

        return $drift;
    }

    /**
     * A path inside the backup, or a refusal.
     *
     * A manifest is data, and a restore that concatenated `$backup.'/'.$path`
     * without looking would write wherever `../../` pointed. Checked before
     * anything is read, because the read is what does the damage.
     */
    public function safePath(string $directory, string $entry): string
    {
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '..') || str_contains($entry, "\0")) {
            throw BackupException::unsafePath($entry);
        }

        // Windows-style roots and drive letters would also escape, and cost
        // nothing to refuse on a platform that never produces them.
        if (preg_match('/^[a-zA-Z]:|^\\\\/', $entry) === 1) {
            throw BackupException::unsafePath($entry);
        }

        return rtrim($directory, '/').'/'.$entry;
    }
}
