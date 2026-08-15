<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use Illuminate\Support\Carbon;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Events\IngestionBatchCompleted;
use SaniTube\Ingestion\Models\IngestionBatch;

/**
 * Decides what a finished batch amounted to.
 *
 * Idempotent, and it has to be: it runs whenever an item finishes and finds no
 * work left, which under concurrency is more than once. Running it twice must
 * be indistinguishable from running it once.
 *
 * The outcome is deliberately three-valued. Importing 900 files and losing
 * four is not a success and not a failure, and a status that can only say one
 * of those is a status that hides the four.
 */
final readonly class FinalizeIngestionBatch
{
    public function handle(IngestionBatch $batch): IngestionBatch
    {
        if ($batch->status->isTerminal()) {
            return $batch;
        }

        if ($batch->hasWorkRemaining()) {
            return $batch;
        }

        $counts = $batch->itemCounts();
        $failed = $counts[IngestionItemStatus::Failed->value];
        $succeeded = $counts[IngestionItemStatus::Imported->value]
            + $counts[IngestionItemStatus::Duplicate->value];

        $status = match (true) {
            // Nothing got in at all, and something tried to. That is a failed
            // import, not a completed one that happens to be empty.
            $succeeded === 0 && $failed > 0 => IngestionBatchStatus::Failed,
            $failed > 0 => IngestionBatchStatus::CompletedWithErrors,
            default => IngestionBatchStatus::Completed,
        };

        $batch->forceFill([
            'status' => $status,
            'completed_at' => Carbon::now(),
        ])->save();

        event(new IngestionBatchCompleted($batch->refresh()));

        return $batch;
    }
}
