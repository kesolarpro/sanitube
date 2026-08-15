<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Models\IngestionBatch;

/**
 * The list of import operations.
 *
 * Ordered by `(created_at, uuid)` — most recent last, like every other list
 * here, and stable because `created_at` alone is not unique when two batches
 * are started in the same second.
 *
 * **Counts for the whole page come from one grouped query.** `IngestionBatch`
 * has an `itemCounts()` that is correct and cheap for one batch, and calling it
 * per row would be fifty queries for a page of fifty. That would be invisible
 * in development, where nobody has fifty batches, and would be the first thing
 * to hurt on the machine that actually ran nine hundred imports.
 *
 * No storage vocabulary leaves here: no provider, no prefix, no object key.
 * Where somebody's material came from is not something a listing screen
 * publishes.
 */
final readonly class IngestionBatchIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = IngestionBatch::query();

        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('created_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'status' => array_map(
                static fn (IngestionBatchStatus $case): string => $case->value,
                IngestionBatchStatus::cases(),
            ),
            'source' => array_map(
                static fn (IngestionSource $case): string => $case->value,
                IngestionSource::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, IngestionBatch>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        /** @var list<IngestionBatch> $batches */
        $batches = $page->items();

        $counts = $this->countsFor($batches);

        $rows = [];

        foreach ($batches as $batch) {
            $rows[] = [
                'uuid' => $batch->uuid,
                'source' => $batch->source->value,
                'status' => $batch->status->value,
                'items' => $counts[$batch->getKey()] ?? $this->zeroed(),
                'started_at' => $batch->started_at?->toAtomString(),
                'completed_at' => $batch->completed_at?->toAtomString(),
                'created_at' => $batch->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * Item counts for every batch on the page, in one query.
     *
     * @param  list<IngestionBatch>  $batches
     * @return array<int, array<string, int>>
     */
    private function countsFor(array $batches): array
    {
        if ($batches === []) {
            return [];
        }

        $ids = array_map(static fn (IngestionBatch $batch): int => $batch->getKey(), $batches);

        $rows = DB::table('ingestion_items')
            ->selectRaw('batch_id, status, count(*) as aggregate')
            ->whereIn('batch_id', $ids)
            ->groupBy('batch_id', 'status')
            ->get();

        $counts = [];

        // Every batch on the page gets a full set of buckets, including the
        // empty ones. A state with no rows must read as a real 0 — "no
        // failures" and "the failed bucket fell out of the result set" call
        // for opposite reactions from whoever is watching an import.
        foreach ($ids as $id) {
            $counts[$id] = $this->zeroed();
        }

        foreach ($rows as $row) {
            /** @var object{batch_id: int, status: string, aggregate: int|string} $row */
            $batchId = (int) $row->batch_id;
            $status = (string) $row->status;

            if (! array_key_exists($status, $counts[$batchId])) {
                // A status the enum no longer declares. Counted into the total
                // rather than dropped: an item that exists is an item, and a
                // total that silently excludes it is a total nobody can
                // reconcile against the item list.
                $counts[$batchId]['total'] += (int) $row->aggregate;

                continue;
            }

            $counts[$batchId][$status] = (int) $row->aggregate;
            $counts[$batchId]['total'] += (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function zeroed(): array
    {
        $buckets = ['total' => 0];

        foreach (IngestionItemStatus::cases() as $case) {
            $buckets[$case->value] = 0;
        }

        return $buckets;
    }

    /**
     * @param  Builder<IngestionBatch>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', IngestionBatchStatus::from($status)->value);
        }

        $source = $filters['source'] ?? null;

        if (is_string($source) && $source !== '') {
            $query->where('source', IngestionSource::from($source)->value);
        }
    }

    /**
     * A cursor from another list is a mangled URL, not a server fault.
     */
    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! self::describesThisOrdering($cursor)) {
            throw new InvalidArgumentException('cursor');
        }

        return $cursor;
    }
}
