<?php

declare(strict_types=1);

namespace SaniTube\Installer;

/**
 * How far an installation has got.
 *
 * Derived from the installation itself rather than from a marker file, and
 * that choice is the point. A `installed.lock` says somebody once ran the
 * installer; it says nothing about whether the database still has the tables,
 * whether an owner still exists, or whether the file was copied from another
 * server along with the rest of the deployment. Every field below is a
 * question asked of the thing it describes.
 *
 * `null` means **could not be determined** — an unreachable database, for
 * instance — and is deliberately distinct from `false`. "There is no owner"
 * and "I could not ask whether there is an owner" lead to opposite next steps.
 */
final readonly class InstallationStatus
{
    public function __construct(
        public bool $hasApplicationKey,
        public ?bool $databaseReachable,
        public ?bool $migrationsRun,
        public ?bool $hasActiveOwner,
    ) {}

    /**
     * Whether this installation is usable as it stands.
     *
     * All four have to be definitely true. An unknown is not a yes: an
     * installer that treated "I could not reach the database" as installed
     * would lock itself out of the machine it was supposed to set up.
     */
    public function isComplete(): bool
    {
        return $this->hasApplicationKey
            && $this->databaseReachable === true
            && $this->migrationsRun === true
            && $this->hasActiveOwner === true;
    }

    /**
     * @return array<string, bool|null>
     */
    public function toArray(): array
    {
        return [
            'application_key' => $this->hasApplicationKey,
            'database_reachable' => $this->databaseReachable,
            'migrations_run' => $this->migrationsRun,
            'active_owner' => $this->hasActiveOwner,
            'complete' => $this->isComplete(),
        ];
    }
}
