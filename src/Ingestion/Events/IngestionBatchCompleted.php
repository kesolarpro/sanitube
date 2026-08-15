<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Ingestion\Models\IngestionBatch;

/**
 * A batch reached a terminal state — including one with errors in it.
 *
 * Carries the batch rather than a summary: how many failed, and which, is a
 * query, and a count baked into an event is a count that was true once.
 */
final class IngestionBatchCompleted
{
    use Dispatchable;

    public function __construct(public readonly IngestionBatch $batch) {}
}
