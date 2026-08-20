<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use Illuminate\Support\Carbon;
use SaniTube\Artwork\Enums\ArtworkGenerationStatus;
use SaniTube\Artwork\Models\ArtworkGeneration;
use SaniTube\MusicGeneration\Services\GenerationCircuitBreaker;

/**
 * Stops asking a provider that has stopped answering.
 *
 * The same shape {@see GenerationCircuitBreaker} uses, and per provider for the
 * same reason: an installation with two providers configured should not lose
 * both because one of them is having an afternoon.
 *
 * **Consecutive failures, newest first.** Not a failure *rate*: a provider that
 * has failed the last five times in a row is broken now, whatever its average
 * over the month says. One success closes the breaker, because one success is
 * evidence the provider is answering again.
 *
 * **The cooldown is what makes it a breaker rather than a fuse.** Without it an
 * outage would disable generation until somebody noticed and intervened, which
 * on an unattended installation means until somebody noticed.
 */
final readonly class ArtworkCircuitBreaker
{
    public function isOpenFor(string $provider): bool
    {
        $threshold = $this->threshold();

        if ($threshold <= 0) {
            // Zero disables the breaker. A legitimate setting for an operator
            // who would rather see every failure than have the platform decide
            // to stop asking.
            return false;
        }

        /** @var list<ArtworkGeneration> $recent */
        $recent = ArtworkGeneration::query()
            ->where('provider', $provider)
            ->whereIn('status', [
                ArtworkGenerationStatus::Completed->value,
                ArtworkGenerationStatus::Failed->value,
            ])
            ->whereNotNull('completed_at')
            // A deterministic tiebreak, ADR-0017: two rows completing inside
            // the same second must order the same way on every engine, or the
            // breaker opens on one and not another.
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit($threshold)
            ->get()
            ->all();

        if (count($recent) < $threshold) {
            // Not enough evidence. A new installation whose first request fails
            // has not established that its provider is broken.
            return false;
        }

        foreach ($recent as $generation) {
            if ($generation->status !== ArtworkGenerationStatus::Failed) {
                return false;
            }
        }

        $mostRecent = $recent[0]->completed_at;

        if (! $mostRecent instanceof Carbon) {
            return false;
        }

        // Cutoff computed in PHP and compared as a value — ADR-0017 again.
        return $mostRecent->greaterThan(Carbon::now()->subMinutes($this->cooldownMinutes()));
    }

    public function threshold(): int
    {
        return max(0, (int) config('artwork.circuit.consecutive_failures', 5));
    }

    public function cooldownMinutes(): int
    {
        return max(1, (int) config('artwork.circuit.cooldown_minutes', 15));
    }
}
