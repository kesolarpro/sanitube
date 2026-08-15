<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * The review queue.
 *
 * Ordered by `(created_at, uuid)`. Not by status, and not with anything
 * promoted to the top: a queue that reorders itself by urgency is one where
 * the position of a row means something different every time it is loaded, and
 * a reviewer working through nine hundred files needs the list to stay where
 * they left it.
 *
 * `suggested_title` keeps its name all the way to the browser. A field called
 * `title` gets rendered beside real catalogue titles and read as one — and the
 * entire point of a candidate is that nothing on it is canonical yet.
 */
final readonly class TrackCandidateIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = TrackCandidate::query();

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
                static fn (TrackCandidateStatus $case): string => $case->value,
                TrackCandidateStatus::cases(),
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
     * @param  CursorPaginator<int, TrackCandidate>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $candidate) {
            $metadata = is_array($candidate->metadata) ? $candidate->metadata : [];

            $rows[] = [
                'uuid' => $candidate->uuid,
                'status' => $candidate->status->value,
                'source' => $candidate->source->value,
                'original_filename' => $candidate->original_filename,
                'suggested_title' => $candidate->suggested_title,

                // Two booleans rather than the payloads. A list is for finding
                // the row worth opening; the reasons themselves need the room a
                // detail screen has.
                'is_duplicate' => $candidate->matched_asset_id !== null,
                'has_conflicts' => ($metadata['manifest_conflicts'] ?? []) !== [],

                'reviewed_at' => $candidate->reviewed_at?->toAtomString(),
                'created_at' => $candidate->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<TrackCandidate>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', TrackCandidateStatus::from($status)->value);
        }

        $source = $filters['source'] ?? null;

        if (is_string($source) && $source !== '') {
            $query->where('source', IngestionSource::from($source)->value);
        }

        $awaiting = $filters['awaiting_review'] ?? null;

        if ($awaiting === true) {
            // What a reviewer actually opens the screen for. READY is included
            // because a candidate that is ready to promote is still waiting for
            // somebody to promote it — "awaiting review" is about the decision,
            // not about the platform having finished its part.
            $query->whereIn('status', [
                TrackCandidateStatus::Ready->value,
                TrackCandidateStatus::NeedsReview->value,
                TrackCandidateStatus::Duplicate->value,
            ]);
        }
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
