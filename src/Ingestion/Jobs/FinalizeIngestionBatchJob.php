<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;

/**
 * Works out what a finished batch amounted to.
 *
 * Safe to run more than once, and it will be: under concurrency two items can
 * each finish believing they were the last.

 * **Safely retryable.** Finalising is a fold over the item rows: it derives the
 * batch's state from what is there now, so running it again on an unchanged
 * batch produces the same answer and on a changed one produces the current
 * one.
 */
final class FinalizeIngestionBatchJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $batchUuid) {}

    public function handle(FinalizeIngestionBatch $finalizer): void
    {
        $batch = IngestionBatch::query()->where('uuid', $this->batchUuid)->first();

        if ($batch instanceof IngestionBatch) {
            $finalizer->handle($batch);
        }
    }
}
