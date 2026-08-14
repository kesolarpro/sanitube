<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

/**
 * Reports which queue backend is in use.
 *
 * The database driver is the portable default and is fully supported; `sync`
 * is the one genuinely dangerous choice, because long ingestion and
 * distribution jobs would then run inside the web request.
 */
final readonly class QueueDetector implements CapabilityDetector
{
    public function detect(): Capability
    {
        $connection = (string) config('queue.default', 'database');
        $driver = (string) config(sprintf('queue.connections.%s.driver', $connection), $connection);

        return match ($driver) {
            'sync' => Capability::degraded(
                key: 'queue',
                label: 'Queue',
                detail: 'Running synchronously: jobs execute inside the web request.',
                remediation: 'Set QUEUE_CONNECTION=database (portable, works on cPanel) or redis, '
                    .'then run a worker via Supervisor, systemd, or a cron running '
                    .'`php artisan queue:work --stop-when-empty`.',
            ),
            'database' => Capability::available(
                key: 'queue',
                label: 'Queue',
                detail: 'Database queue — portable, no Redis required.',
            ),
            'redis' => Capability::available('queue', 'Queue', 'Redis queue.'),
            default => Capability::available('queue', 'Queue', sprintf('Driver [%s].', $driver)),
        };
    }
}
