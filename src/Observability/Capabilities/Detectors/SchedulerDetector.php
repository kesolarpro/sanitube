<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use DateTimeImmutable;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use SaniTube\Observability\SchedulerHeartbeat;

final readonly class SchedulerDetector implements CapabilityDetector
{
    /** How long a heartbeat may be missing before the scheduler is considered stalled. */
    public const STALE_AFTER_SECONDS = 900;

    public function __construct(private SchedulerHeartbeat $heartbeat) {}

    public function detect(): Capability
    {
        $lastRun = $this->heartbeat->lastRunAt();

        if ($lastRun === null) {
            return Capability::unavailable(
                key: 'scheduler',
                label: 'Scheduler',
                detail: 'No scheduler heartbeat could be read.',
                remediation: 'Either the cron entry is missing — add: * * * * * php '
                    .base_path('artisan').' schedule:run >> /dev/null 2>&1 — '
                    .'or the cache store is unreachable, in which case fix that first.',
            );
        }

        $age = (new DateTimeImmutable)->getTimestamp() - $lastRun->getTimestamp();

        if ($age > self::STALE_AFTER_SECONDS) {
            return Capability::degraded(
                key: 'scheduler',
                label: 'Scheduler',
                detail: sprintf('Last heartbeat was %d minutes ago.', intdiv($age, 60)),
                remediation: 'The cron entry exists but is no longer firing. Check the cron log and the PHP binary path.',
            );
        }

        return Capability::available(
            key: 'scheduler',
            label: 'Scheduler',
            detail: sprintf('Last heartbeat %s.', $lastRun->format(DATE_ATOM)),
        );
    }
}
