<?php

declare(strict_types=1);

namespace SaniTube\Audit\Services;

use Illuminate\Support\Carbon;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;

/**
 * The one way rows leave the audit log.
 *
 * An operational log grows for as long as the platform is used, and a table
 * nobody ever removes anything from eventually becomes a table nobody can
 * query and a backup nobody can restore in the window they have. So there is a
 * retention period — and everything about how it is applied is chosen to keep
 * pruning from becoming a way to lose history quietly.
 *
 * **It never runs by itself.** There is no prune-on-write, no probability, no
 * scheduled default. Somebody runs the command, or an installation schedules
 * it having decided the period. A log that trims itself as a side effect of
 * being written to is a log whose oldest entries disappear exactly when it is
 * busiest — which is exactly when they matter.
 *
 * **It deletes whole periods, never selected rows.** The only argument is a
 * cut-off. There is no way to say "remove the events about this person" or
 * "remove the refusals", because those are the requests an audit log exists to
 * refuse.
 *
 * **It records that it ran, before it runs.** The pruning event is written
 * first and carries the cut-off and the count, so the gap in the history has a
 * line explaining itself. Writing it afterwards would leave a window in which
 * the removal had happened and nothing said so — and would also let a failure
 * halfway through remove rows with no record at all.
 *
 * The deletion goes through the query builder rather than `$event->delete()`,
 * which the model refuses. That is not a workaround: it is the distinction the
 * model is drawing. Holding a record and deleting it is erasing one thing;
 * removing everything before a date is a retention policy.
 */
final readonly class PruneAuditLog
{
    /** Below this, a "retention period" is a way of turning the log off. */
    public const MINIMUM_DAYS = 30;

    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Remove everything that happened before the cut-off.
     *
     * @param  int  $days  how much history to keep, clamped to at least {@see self::MINIMUM_DAYS}
     * @return int the number of events removed
     */
    public function handle(int $days): int
    {
        $days = max(self::MINIMUM_DAYS, $days);
        $cutoff = Carbon::now()->subDays($days);

        $doomed = AuditEvent::query()->where('occurred_at', '<', $cutoff)->count();

        if ($doomed === 0) {
            // Nothing to say. A line every night saying nothing was removed is
            // how a log stops being read.
            return 0;
        }

        // First, so the gap explains itself even if the delete then fails.
        $this->audit->record(
            AuditAction::AuditLogPruned,
            context: ['retention_days' => $days, 'before' => $cutoff->toAtomString(), 'removed' => $doomed],
        );

        return AuditEvent::query()->where('occurred_at', '<', $cutoff)->delete();
    }
}
