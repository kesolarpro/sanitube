<?php

declare(strict_types=1);

namespace SaniTube\Operations\Services;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * How much more work the queue has room for.
 *
 * **The failure this exists to prevent is specific.** An import of nine hundred
 * files enqueues nine hundred jobs in a loop, and a backfill over an existing
 * library would enqueue fifty thousand. On this platform's baseline — a
 * database queue drained by a cron-driven worker on a shared account — that is
 * not slow, it is a way to take the installation down: the jobs table grows
 * faster than it drains, every other feature that needs a job waits behind it,
 * and the operator's only remedy is a `DELETE` they should not be running.
 *
 * So the ceiling is a *refusal to start*, not a throttle. Half-enqueuing a
 * batch and leaving the remainder to a sweep that may never come is how work
 * gets stranded in a state nobody is watching; refusing the whole request tells
 * somebody now, while they are still looking at it.
 *
 * The depth comes from the queue driver itself rather than from a counter this
 * platform keeps, because a counter is a second source of truth that drifts the
 * first time a job is deleted by hand.
 */
final readonly class BacklogGuard
{
    public function __construct(private QueueFactory $queue) {}

    /**
     * Zero means no ceiling.
     *
     * A legitimate setting on a machine with a real worker, and the reason the
     * default is a number rather than a fraction of anything: a proportion of
     * an unknown machine is not a limit anybody can reason about.
     */
    public function ceiling(): int
    {
        return max(0, (int) config('operations.backlog.ceiling', 10000));
    }

    /**
     * How deep the queue is right now, or null when the driver cannot say.
     *
     * Null rather than zero, and the difference matters: zero would let an
     * unmeasurable queue read as an empty one and disable the guard silently on
     * exactly the drivers it cannot see into.
     */
    public function depth(): ?int
    {
        try {
            return $this->queue->connection()->size();
        } catch (Throwable) {
            return null;
        }
    }

    public function wouldExceedCeiling(int $additionalJobs): bool
    {
        $ceiling = $this->ceiling();

        if ($ceiling === 0) {
            return false;
        }

        $depth = $this->depth();

        // Unmeasurable. Admitting the work is the right call: refusing every
        // import on a driver that cannot report its size would break the
        // platform on a configuration it is meant to support, and the ceiling
        // is a safeguard rather than a correctness requirement.
        if ($depth === null) {
            return false;
        }

        return ($depth + $additionalJobs) > $ceiling;
    }
}
