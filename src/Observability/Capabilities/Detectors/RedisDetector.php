<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Predis\Client;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use Throwable;

/**
 * Redis is an optimisation, never a requirement.
 *
 * Its absence is normal on shared hosting and is reported as such; the queue
 * and cache detectors independently report which driver is actually in use.
 */
final readonly class RedisDetector implements CapabilityDetector
{
    public function __construct(private Container $container) {}

    public function detect(): Capability
    {
        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            return Capability::optional(
                key: 'redis',
                label: 'Redis',
                detail: 'No Redis client available (neither the phpredis extension nor predis).',
                remediation: 'Not required. Install phpredis or composer require predis/predis '
                    .'only if you want Redis-backed queues and cache.',
            );
        }

        try {
            $this->container->make(RedisFactory::class)->connection()->command('ping', []);
        } catch (Throwable $exception) {
            return Capability::optional(
                key: 'redis',
                label: 'Redis',
                detail: 'A Redis client is installed but the server did not answer: '.$exception->getMessage(),
                remediation: 'Not required. Check REDIS_HOST/REDIS_PORT, or leave the database drivers in place.',
            );
        }

        return Capability::available('redis', 'Redis', 'Reachable.');
    }
}
