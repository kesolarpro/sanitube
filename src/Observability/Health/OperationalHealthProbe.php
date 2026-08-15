<?php

declare(strict_types=1);

namespace SaniTube\Observability\Health;

use DateTimeImmutable;
use SaniTube\AI\AiManager;
use SaniTube\Distribution\DistributorManager;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * The expensive half of health checking, isolated so it can only run on purpose.
 *
 * Everything in here talks to something outside the process: a storage provider
 * that writes and deletes a probe object, provider availability calls that may
 * cross the network, a capability sweep that shells out. On a shared cPanel
 * account those add up to seconds, and none of them belong in a page request.
 *
 * So this class has exactly one caller path — the scheduled refresh command,
 * and the explicit operator-triggered refresh — and screens never construct it.
 *
 * A probe that throws is recorded as **unknown**, never as unhealthy. "The
 * provider said no" and "we could not reach the provider to ask" send an
 * operator to different places, and collapsing them wastes the trip.
 */
final readonly class OperationalHealthProbe
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private AiManager $ai,
        private MusicGenerationManager $generation,
        private DistributorManager $distributors,
        private StorageManager $storage,
    ) {}

    public function run(?DateTimeImmutable $now = null): OperationalHealth
    {
        return new OperationalHealth(
            checkedAt: $now ?? new DateTimeImmutable,
            storage: $this->storage(),
            ai: $this->provider(
                fn (): string => $this->ai->defaultName(),
                fn (): bool => $this->ai->isAvailable(),
            ),
            generation: $this->provider(
                fn (): string => $this->generation->defaultName(),
                fn (): bool => $this->generation->isAvailable(),
            ),
            distributors: $this->distributors(),
            capabilities: $this->capabilities(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        try {
            $name = $this->storage->defaultName();
        } catch (Throwable) {
            return ['provider' => null, 'healthy' => null, 'checks' => [], 'temporary_urls' => null, 'detail' => null];
        }

        try {
            $health = $this->storage->provider($name)->healthCheck();

            return [
                'provider' => $health->provider,
                'healthy' => $health->healthy,
                'checks' => $health->checks,
                'temporary_urls' => $health->temporaryUrls,
                // Redaction already happened at the StorageHealth constructor,
                // which is the single place every failing report passes through.
                'detail' => $health->detail,
            ];
        } catch (Throwable) {
            return ['provider' => $name, 'healthy' => null, 'checks' => [], 'temporary_urls' => null, 'detail' => null];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function distributors(): array
    {
        try {
            $names = $this->distributors->names();
        } catch (Throwable) {
            return [];
        }

        $states = [];

        foreach ($names as $name) {
            $states[] = $this->provider(
                fn (): string => $name,
                fn (): bool => $this->distributors->distributor($name)->isAvailable(),
            );
        }

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        try {
            $report = $this->capabilities->report();

            return ['healthy' => $report->isHealthy(), 'items' => $report->toArray()];
        } catch (Throwable) {
            return ['healthy' => null, 'items' => []];
        }
    }

    /**
     * @param  callable(): string  $name
     * @param  callable(): bool  $available
     * @return array<string, mixed>
     */
    private function provider(callable $name, callable $available): array
    {
        try {
            $resolved = $name();
        } catch (Throwable) {
            return ['name' => null, 'available' => null];
        }

        try {
            return ['name' => $resolved, 'available' => $available()];
        } catch (Throwable) {
            return ['name' => $resolved, 'available' => null];
        }
    }
}
