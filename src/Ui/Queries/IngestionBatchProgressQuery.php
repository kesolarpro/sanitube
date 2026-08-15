<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Support\Facades\DB;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Models\IngestionBatch;

/**
 * How far a batch has got, counted from the items themselves.
 *
 * **Derived, never stored.** A batch of nine hundred files could carry
 * `completed`, `failed` and `duplicate` columns updated as each item finishes,
 * and that would be faster to read — but it would be a second source of truth
 * for a fact the item rows already hold. Every crashed worker, every retry and
 * every manual intervention is then an opportunity for the counters to drift
 * from reality, and a progress screen that disagrees with the item list is
 * worse than no progress screen: it is one an operator stops believing.
 *
 * The cost of deriving it is one grouped count per batch. If that ever becomes
 * the bottleneck, the answer is a materialised projection that can be rebuilt
 * from the items on demand — not a counter that is only ever incremented.
 *
 * Two figures are deliberately absent. There is no percentage and no estimated
 * completion time: both would be invented, because item durations vary by
 * orders of magnitude between a three-minute MP3 and an hour-long WAV, and a
 * progress bar that stalls at 94% teaches people to ignore progress bars.
 */
final readonly class IngestionBatchProgressQuery
{
    /**
     * @return array<string, mixed>
     */
    public function forBatch(IngestionBatch $batch): array
    {
        return [
            'uuid' => $batch->uuid,
            'status' => $batch->status->value,
            'source' => $batch->source->value,
            'started_at' => $batch->started_at?->toAtomString(),
            'completed_at' => $batch->completed_at?->toAtomString(),
            'items' => $this->counts($batch),
        ];
    }

    /**
     * Every item state, including the empty ones.
     *
     * Expanded against the enum rather than trusted from the result set: a
     * state with no rows must read as a real `0`, because "no failures" and
     * "the failed bucket fell out of the query" call for opposite reactions
     * from whoever is watching an import of nine hundred files.
     *
     * @return array<string, int>
     */
    private function counts(IngestionBatch $batch): array
    {
        /** @var array<string, int> $counted */
        $counted = DB::table('ingestion_items')
            ->selectRaw('status, count(*) as aggregate')
            ->where('batch_id', $batch->getKey())
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $totals = ['total' => 0];

        foreach (IngestionItemStatus::cases() as $case) {
            $count = (int) ($counted[$case->value] ?? 0);

            $totals[$case->value] = $count;
            $totals['total'] += $count;
        }

        return $totals;
    }
}
