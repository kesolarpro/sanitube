<?php

declare(strict_types=1);

namespace SaniTube\Observability;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Proof that the scheduler is actually running.
 *
 * A cron entry that was never created is one of the most common — and most
 * silent — production failures: distribution sync, royalty imports and
 * retries simply never happen. A once-a-minute heartbeat turns that into a
 * visible capability.
 */
final readonly class SchedulerHeartbeat
{
    public const CACHE_KEY = 'sanitube:scheduler:heartbeat';

    public function __construct(private Cache $cache) {}

    public function record(?DateTimeImmutable $at = null): void
    {
        $at ??= new DateTimeImmutable;

        // Kept well beyond the staleness threshold so "stale" and "never ran"
        // remain distinguishable.
        $this->cache->put(self::CACHE_KEY, $at->format(DATE_ATOM), now()->addDay());
    }

    public function lastRunAt(): ?DateTimeImmutable
    {
        try {
            $value = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            // The cache store itself is unreachable — on the default database
            // store that means the database is down. Reporting "no heartbeat"
            // is correct and lets the database detector explain the real cause,
            // instead of the health screen dying on the way there.
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $parsed === false ? null : $parsed;
    }
}
