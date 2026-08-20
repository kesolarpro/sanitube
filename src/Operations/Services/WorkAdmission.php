<?php

declare(strict_types=1);

namespace SaniTube\Operations\Services;

use SaniTube\Operations\Exceptions\WorkRefused;

/**
 * The one question asked before a pile of work is enqueued.
 *
 * Two gates, and they answer different questions. {@see BackgroundWork} is
 * "should this installation be doing anything at all right now" — a person's
 * decision. {@see BacklogGuard} is "is there room" — a measurement. Keeping
 * them separate means an operator can see which one stopped them, and lifting
 * one does not lift the other.
 *
 * Asked **before** the work exists, not while it is being created. The
 * ingestion service already decided that a request naming five hundred
 * unacceptable objects should fail once in the response rather than five
 * hundred times in a queue nobody is watching; this is the same principle
 * applied to the queue's own capacity.
 */
final readonly class WorkAdmission
{
    public function __construct(
        private BackgroundWork $work,
        private BacklogGuard $backlog,
    ) {}

    /**
     * @param  int  $jobs  how many jobs the caller is about to enqueue
     *
     * @throws WorkRefused
     */
    public function admit(int $jobs): void
    {
        if ($this->work->isPaused()) {
            throw WorkRefused::paused();
        }

        if ($this->backlog->wouldExceedCeiling($jobs)) {
            throw WorkRefused::backlogSaturated($this->backlog->depth() ?? 0, $this->backlog->ceiling());
        }
    }

    /**
     * Whether the work would be admitted, without refusing.
     *
     * For a screen deciding whether to offer a button. Presentation only — the
     * refusal in {@see admit()} is the enforcement, and an interface that
     * checked this instead would be a permission check on the client.
     */
    public function wouldAdmit(int $jobs): bool
    {
        return ! $this->work->isPaused() && ! $this->backlog->wouldExceedCeiling($jobs);
    }
}
