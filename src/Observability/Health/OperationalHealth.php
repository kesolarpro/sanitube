<?php

declare(strict_types=1);

namespace SaniTube\Observability\Health;

use DateTimeImmutable;
use Throwable;

/**
 * What the outside world looked like the last time somebody actually asked it.
 *
 * Every field here is the result of a *real* probe — a storage round trip, a
 * provider availability call, a capability sweep. Those cost network time and,
 * in the case of storage, an object written and deleted. None of that may
 * happen inside a page request, which is the whole reason this value object
 * exists: the probing is done on a schedule, the result is stored, and screens
 * read the stored result.
 *
 * `checkedAt` is therefore not decoration. A reader cannot judge any of these
 * values without knowing how old they are, and a snapshot with no timestamp is
 * indistinguishable from a fresh one.
 */
final readonly class OperationalHealth
{
    /**
     * @param  array<string, mixed>  $storage
     * @param  array<string, mixed>  $ai
     * @param  array<string, mixed>  $generation
     * @param  list<array<string, mixed>>  $distributors
     * @param  array<string, mixed>  $capabilities
     */
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public array $storage,
        public array $ai,
        public array $generation,
        public array $distributors,
        public array $capabilities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // ATOM rather than a timestamp: the cache may be the database, a
            // file, or Redis, and a portable string survives all three without
            // depending on how each serialises objects.
            'checked_at' => $this->checkedAt->format(DATE_ATOM),
            'storage' => $this->storage,
            'ai' => $this->ai,
            'generation' => $this->generation,
            'distributors' => $this->distributors,
            'capabilities' => $this->capabilities,
        ];
    }

    /**
     * Rebuild from whatever was in the cache, or null if it cannot be trusted.
     *
     * A snapshot written by an older version of the application, or truncated
     * by a cache backend, must read as *absent* rather than as a partially
     * populated report. A half-parsed health report is worse than no health
     * report: the missing halves would render as "unknown" while the surviving
     * halves would look authoritative.
     *
     * @param  mixed  $payload
     */
    public static function fromCache($payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['checked_at', 'storage', 'ai', 'generation', 'distributors', 'capabilities'] as $key) {
            if (! array_key_exists($key, $payload)) {
                return null;
            }
        }

        if (! is_string($payload['checked_at'])) {
            return null;
        }

        try {
            $checkedAt = new DateTimeImmutable($payload['checked_at']);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed> $storage */
        $storage = is_array($payload['storage']) ? $payload['storage'] : [];
        /** @var array<string, mixed> $ai */
        $ai = is_array($payload['ai']) ? $payload['ai'] : [];
        /** @var array<string, mixed> $generation */
        $generation = is_array($payload['generation']) ? $payload['generation'] : [];
        /** @var list<array<string, mixed>> $distributors */
        $distributors = is_array($payload['distributors']) ? array_values($payload['distributors']) : [];
        /** @var array<string, mixed> $capabilities */
        $capabilities = is_array($payload['capabilities']) ? $payload['capabilities'] : [];

        return new self($checkedAt, $storage, $ai, $generation, $distributors, $capabilities);
    }

    public function ageInSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->checkedAt->getTimestamp());
    }
}
