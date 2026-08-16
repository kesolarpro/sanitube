<?php

declare(strict_types=1);

namespace SaniTube\Audit\Console;

use Illuminate\Console\Command;
use SaniTube\Audit\Services\PruneAuditLog;

/**
 * Applies the retention period, when somebody asks.
 *
 * Not scheduled by SaniTube. How long an installation must keep its
 * operational history is a decision about that installation — a label with a
 * distribution agreement and a hobbyist on shared hosting do not have the same
 * answer — and shipping a default that quietly deletes a year of history on
 * first cron run would be making it for them.
 *
 * `--days` is clamped upward rather than validated: a retention of three days
 * is not a retention policy, it is a way of turning the log off, and refusing
 * outright would only invite a scheduled `|| true`.
 */
final class PruneAuditLogCommand extends Command
{
    protected $signature = 'sanitube:audit:prune {--days=365 : How much history to keep}';

    protected $description = 'Remove audit events older than the retention period.';

    public function handle(PruneAuditLog $prune): int
    {
        $requested = (int) $this->option('days');
        $days = max(PruneAuditLog::MINIMUM_DAYS, $requested);

        if ($days !== $requested) {
            $this->components->warn(sprintf(
                'A retention of %d days is below the %d-day floor and was raised to it.',
                $requested,
                $days,
            ));
        }

        $removed = $prune->handle($days);

        $this->components->info(sprintf(
            $removed === 0
                ? 'Nothing to remove: no audit event is older than %2$d days.'
                : '%1$d audit events older than %2$d days removed. The removal is itself recorded.',
            $removed,
            $days,
        ));

        return self::SUCCESS;
    }
}
