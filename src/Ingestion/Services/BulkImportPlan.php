<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

/**
 * How much of a selection this run will take, and how much is left.
 *
 * A catalogue of four thousand files does not become one batch. A batch is a
 * unit of work a person can inspect and, when it goes wrong, reason about —
 * eight batches of five hundred that half-failed are diagnosable, one batch of
 * four thousand is a wall of rows. So the platform's answer to a large
 * catalogue is *the same command, run again*, and this is what it reports
 * between runs.
 *
 * **`alreadyHandled` is the number that makes it resumable.** It is not "files
 * we would skip": those items exist, they were imported, deduplicated or sent
 * to review, and asking the provider for their bytes a second time would cost
 * real egress to learn something the database already knows.
 */
final readonly class BulkImportPlan
{
    /**
     * @param  int  $found  acceptable references the selection resolved to
     * @param  int  $alreadyHandled  references an item already exists for, in a state that is not a retry
     * @param  list<string>  $references  what this run will import, in listing order
     * @param  int  $remaining  references left for the next run
     */
    public function __construct(
        public int $found,
        public int $alreadyHandled,
        public array $references,
        public int $remaining,
    ) {}

    public function isEmpty(): bool
    {
        return $this->references === [];
    }

    /**
     * Whether the selection is finished — everything found has been handled.
     *
     * Distinct from "this run has nothing to do", which is also true when the
     * queue is full or the caller asked for zero. The difference is what an
     * operator is told: *done* rather than *try again*.
     */
    public function isComplete(): bool
    {
        return $this->references === [] && $this->remaining === 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'found' => $this->found,
            'already_handled' => $this->alreadyHandled,
            'this_batch' => count($this->references),
            'remaining' => $this->remaining,
        ];
    }
}
