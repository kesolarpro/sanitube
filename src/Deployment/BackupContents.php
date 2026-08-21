<?php

declare(strict_types=1);

namespace SaniTube\Deployment;

use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\Services\BackupRepository;

/**
 * What may go into a backup, and what never may.
 *
 * `backup.include_paths` is the one input to a backup that nothing checked.
 * The destination is checked — {@see BackupRepository::destination()} refuses
 * one inside the web root on every call — but what gets *copied into* it was
 * whatever the configuration said, resolved against the application root and
 * walked. Three things that costs:
 *
 *   - **`../` is a path.** `base_path('../')` is a directory, and a backup
 *     told to include it would copy the parent of the installation. On shared
 *     hosting that is somebody else's account.
 *   - **A backup can contain a backup.** The default destination is inside
 *     `storage/`, so an operator who writes `storage` — the obvious thing to
 *     write for "back up my storage directory" — gets a run that copies every
 *     previous backup into the new one. Each run then doubles, quietly, until
 *     the disk is full and the nightly job has been failing for a week.
 *   - **`.env` is a file.** Every credential this installation holds, in one
 *     place, sitting beside a database dump.
 *
 * So this is the *one* place that decides, and both directions ask it. Taking
 * a backup asks what it may copy; restoring one asks what it may write back.
 * A backup written by an older SaniTube that predates this rule is refused on
 * restore rather than trusted, because "it is in the manifest" is exactly the
 * argument that would put another installation's credentials over the live
 * ones.
 */
final readonly class BackupContents
{
    public function __construct(private BackupRepository $repository) {}

    /**
     * The configured paths that exist, as relative => absolute.
     *
     * Refuses before it resolves anything else, and is called before the
     * backup directory is created: a configuration mistake should stop the run
     * rather than leave a half-written directory somebody has to clean up.
     *
     * @param  list<string>  $configured
     * @return array<string, string>
     */
    public function directories(array $configured): array
    {
        $found = [];

        foreach ($configured as $relative) {
            $absolute = $this->resolve($relative);

            if ($absolute !== null) {
                $found[$relative] = $absolute;
            }
        }

        return $found;
    }

    /**
     * The configured paths that are not there, and what that means.
     *
     * Recorded rather than skipped. A path in the configuration and nothing on
     * disk is an operator who believes something is backed up; the manifest is
     * where they find out otherwise, and it can only say so if this says so.
     *
     * @param  list<string>  $configured
     * @return array<string, string>
     */
    public function omissions(array $configured): array
    {
        $omitted = [];

        foreach ($configured as $relative) {
            if ($this->resolve($relative) === null) {
                $omitted['path:'.$relative] = 'Configured for inclusion and not present on disk when the backup ran. '
                    .'Nothing from it is in this backup.';
            }
        }

        return $omitted;
    }

    /**
     * Whether a file may be copied into, or written out of, a backup.
     *
     * One predicate, asked in both directions, because a rule that only held
     * while writing would be a rule a backup from last year walks straight
     * past.
     */
    public function mayCarry(string $path): bool
    {
        return ! $this->isEnvironmentFile($path);
    }

    /**
     * `.env`, and everything shaped like it, at any depth.
     *
     * Matched on the name rather than the location. `.env` is normally at the
     * application root and nothing says it has to be — and a rule that only
     * knew the usual place would be a rule an unusual installation escapes.
     */
    public function isEnvironmentFile(string $path): bool
    {
        $name = basename(str_replace('\\', '/', $path));

        return $name === '.env' || str_starts_with($name, '.env.');
    }

    /**
     * An absolute directory inside the application, or null if it is not there.
     *
     * @throws BackupException when the path escapes, is the root itself, or
     *                         touches the backup destination
     */
    private function resolve(string $relative): ?string
    {
        $root = rtrim((string) (realpath(base_path()) ?: base_path()), '/');
        $resolved = realpath(base_path($relative));

        if ($resolved === false || ! is_dir($resolved)) {
            return null;
        }

        $absolute = rtrim($resolved, '/');

        // Resolved first, then compared. A textual check on the configured
        // string would be fooled by a symlink pointing out of the tree, and a
        // symlink is how `storage` reaches `public/storage` in every Laravel
        // installation — so links are exactly what is around here.
        if (! str_starts_with($absolute.'/', $root.'/')) {
            throw BackupException::includePathEscapes($relative);
        }

        if ($absolute === $root) {
            throw BackupException::includePathIsTheApplication($relative);
        }

        $destination = $this->destination();

        if (
            $absolute === $destination
            || str_starts_with($destination.'/', $absolute.'/')
            || str_starts_with($absolute.'/', $destination.'/')
        ) {
            throw BackupException::includePathIsTheDestination($relative);
        }

        return $absolute;
    }

    /**
     * Where backups go, resolved if it is there and taken as written if not.
     *
     * The first backup on a new installation runs before the directory exists,
     * and a containment check that could only answer once the thing existed
     * would be a check that never ran on the run that mattered.
     */
    private function destination(): string
    {
        $configured = $this->repository->destination();

        return rtrim((string) (realpath($configured) ?: $configured), '/');
    }
}
