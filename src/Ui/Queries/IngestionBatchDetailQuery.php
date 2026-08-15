<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * One import operation and the items inside it.
 *
 * The counts come from {@see IngestionBatchProgressQuery} rather than being
 * computed again here. Two places deriving the same progress is how a batch
 * detail screen ends up disagreeing with the batch list — and once a screen
 * disagrees with another screen, an operator stops believing both.
 *
 * The item list is paginated because a batch holds up to five hundred of them,
 * and ordered by `(id, uuid)`: items within a batch are created in the order
 * the references were named, which is the order somebody reading a failure
 * report expects to find them in.
 *
 * **No object key appears here.** An item's `source_reference` is a key in
 * somebody's storage, and a screen is not the place to publish the shape of it.
 * `original_filename` — the basename, the thing a person recognises — is what a
 * failure report needs, and is what the candidate already carries.
 */
final readonly class IngestionBatchDetailQuery
{
    public const PER_PAGE = 50;

    public function __construct(private IngestionBatchProgressQuery $progress) {}

    /**
     * @return array<string, mixed>
     */
    public function forBatch(IngestionBatch $batch, ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $page = $batch->items()
            ->orderBy('id')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'batch' => $this->progress->forBatch($batch),
            'items' => [
                'rows' => $this->rows($page),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
                'per_page' => $perPage,
            ],
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['id', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, IngestionItem>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        /** @var list<IngestionItem> $items */
        $items = $page->items();

        $candidateUuids = $this->candidateUuids($items);

        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'uuid' => $item->uuid,
                'original_filename' => $item->original_filename,
                'status' => $item->status->value,
                'attempt_count' => $item->attempt_count,

                // The code, not the message. `failure_message` quotes whatever
                // threw — a storage driver, an analyser — and those quotes
                // routinely contain a path or a bucket name. The code is what a
                // person acts on, and it is translated rather than echoed.
                'failure_code' => $item->failure_code?->value,

                'candidate_uuid' => $item->candidate_id === null
                    ? null
                    : ($candidateUuids[$item->candidate_id] ?? null),

                // Whether the operator's manifest had anything to say about
                // this file. Not the contents: those belong on the candidate,
                // where there is room to show them beside what the file itself
                // turned out to contain.
                'has_manifest_row' => is_array($item->manifest_metadata) && $item->manifest_metadata !== [],

                'created_at' => $item->created_at?->toAtomString(),
                'updated_at' => $item->updated_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * Candidate uuids for the whole page in one query.
     *
     * `ingestion_items.candidate_id` carries no foreign key — a candidate
     * outlives the batch that proposed it — so this is a deliberate lookup
     * rather than an eager load, and it is one query for the page rather than
     * one per row.
     *
     * @param  list<IngestionItem>  $items
     * @return array<int, string>
     */
    private function candidateUuids(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->candidate_id !== null) {
                $ids[] = $item->candidate_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $uuids */
        $uuids = TrackCandidate::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('uuid', 'id')
            ->all();

        return $uuids;
    }

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
