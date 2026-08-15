<?php

declare(strict_types=1);

namespace SaniTube\Observability\Health;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Where the last real probe result lives between refreshes.
 *
 * The cache, not a table: this is derived state with a short life, and adding a
 * migration for it would mean a schema change every time the snapshot's shape
 * moves. The default store is whatever `CACHE_STORE` is — `database` or `file`
 * on the shared-hosting baseline SaniTube targets. Redis works and is faster;
 * nothing here requires it.
 *
 * Reading is deliberately generous and writing deliberately strict: a screen
 * asking for health must never fail because the cache backend is unhappy, but a
 * refresh that could not store its result must say so rather than leave a stale
 * snapshot looking current.
 */
final readonly class OperationalHealthStore
{
    public function __construct(
        private Cache $cache,
        private string $key,
        private int $staleAfterSeconds,
        private int $retentionSeconds,
    ) {}

    /**
     * The stored snapshot, or null when there is none that can be trusted.
     */
    public function current(): ?OperationalHealth
    {
        try {
            return OperationalHealth::fromCache($this->cache->get($this->key));
        } catch (Throwable) {
            // An unreachable cache backend is a reason to report unknown
            // health, never a reason to fail the page that asked.
            return null;
        }
    }

    public function put(OperationalHealth $health): bool
    {
        try {
            // Retained well past the staleness threshold on purpose: an expired
            // entry and a stale one are different facts, and letting the cache
            // silently drop the snapshot would turn "last checked three hours
            // ago" into "never checked", which reads as a fresh install.
            return $this->cache->put($this->key, $health->toArray(), $this->retentionSeconds);
        } catch (Throwable) {
            return false;
        }
    }

    public function isStale(OperationalHealth $health, ?DateTimeImmutable $now = null): bool
    {
        return $health->ageInSeconds($now ?? new DateTimeImmutable) > $this->staleAfterSeconds;
    }

    public function staleAfterSeconds(): int
    {
        return $this->staleAfterSeconds;
    }

    public function forget(): void
    {
        try {
            $this->cache->forget($this->key);
        } catch (Throwable) {
            // Nothing to recover: the caller is discarding state, and a cache
            // that cannot be written to has already discarded it.
        }
    }
}
