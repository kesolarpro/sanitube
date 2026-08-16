<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;

/**
 * One item, one job.
 *
 * Carries the item's **uuid**, not the model. A serialised model is a snapshot
 * of a row taken when the job was queued, and by the time a worker picks it up
 * — minutes later, after a retry, after another attempt already advanced it —
 * that snapshot is a lie. Re-reading the row is what makes the idempotency
 * check in the service mean anything.
 *
 * Finalisation is dispatched from here rather than chained, and only when this
 * item was the last one outstanding. That bounds the work: N item jobs and, in
 * the ordinary case, exactly one finalise. Under a race two workers may both
 * see themselves as last, which is why finalising is idempotent.

 * **Safely retryable.** It re-reads the item row rather than trusting the
 * snapshot it was queued with, and the ingestion key is unique among in-flight
 * items — so a second run of the same intent finds the work already done and
 * records that, rather than importing the bytes twice.
 */
final class ProcessIngestionItemJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $itemUuid) {}

    public function tries(): int
    {
        return max(1, (int) config('ingestion.item_tries', 3));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = (array) config('ingestion.retry_backoff_seconds', [30, 120, 600]);

        return $backoff;
    }

    public function handle(ProcessIngestionItem $processor, FinalizeIngestionBatch $finalizer): void
    {
        $item = IngestionItem::query()->where('uuid', $this->itemUuid)->first();

        if (! $item instanceof IngestionItem) {
            // The batch was deleted under us. Nothing to do, and nothing worth
            // failing a queue worker over.
            return;
        }

        $processed = $processor->handle($item);

        $batch = $processed->batch;

        if (! $batch->hasWorkRemaining()) {
            FinalizeIngestionBatchJob::dispatch($batch->uuid);
        }
    }
}
