<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

/**
 * What a staging sweep did, and to what.
 *
 * Names the removed keys rather than counting them. A cleanup task that
 * reports "12 objects removed" is unauditable; one that says which twelve can
 * be checked against a storage listing afterwards.
 */
final readonly class StagingSweepReport
{
    /**
     * @param  list<string>  $removed
     */
    public function __construct(
        public string $provider,
        public array $removed,
        public int $kept,
        public bool $dryRun = false,
    ) {}

    public function count(): int
    {
        return count($this->removed);
    }

    /**
     * @return array{provider: string, removed: list<string>, removed_count: int, kept: int, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'removed' => $this->removed,
            'removed_count' => $this->count(),
            'kept' => $this->kept,
            'dry_run' => $this->dryRun,
        ];
    }
}
