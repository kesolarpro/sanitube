<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

/**
 * What a staging sweep did, and to what.
 *
 * Names the removed keys rather than counting them. A cleanup task that
 * reports "12 objects removed" is unauditable; one that says which twelve can
 * be checked against a storage listing afterwards.
 *
 * What it *kept* is named too, with the reason. An object that survives every
 * sweep forever is either evidence of a bug or evidence of an attack, and
 * "kept: 1" for six months tells nobody which.
 */
final readonly class StagingSweepReport
{
    /**
     * @param  list<string>  $removed
     * @param  array<string, string>  $skipped  key => why it was left alone
     * @param  int  $ageSeconds  the threshold actually applied, after the floor
     */
    public function __construct(
        public string $provider,
        public array $removed,
        public array $skipped,
        public int $ageSeconds,
        public bool $dryRun = false,
    ) {}

    public function count(): int
    {
        return count($this->removed);
    }

    public function kept(): int
    {
        return count($this->skipped);
    }

    /**
     * @return array{provider: string, removed: list<string>, removed_count: int, skipped: array<string, string>, kept: int, age_seconds: int, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'removed' => $this->removed,
            'removed_count' => $this->count(),
            'skipped' => $this->skipped,
            'kept' => $this->kept(),
            'age_seconds' => $this->ageSeconds,
            'dry_run' => $this->dryRun,
        ];
    }
}
