<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;

/**
 * The duplicate queue.
 *
 * Ordered by `(detected_at, uuid)` and by nothing else. Not by similarity, and
 * not with the strongest evidence promoted to the top: a queue that reorders
 * itself means the position of a row is different every time it loads, and a
 * reviewer working through a few hundred findings needs the list to stay where
 * they left it. The level is a column they can filter on instead.
 *
 * **Both sides of every finding are sent, with their evidence.** A row that
 * said only "these two match" would make the reviewer open two other screens
 * before they could answer, and a queue that costs three page loads per
 * decision is a queue nobody works through. What is sent is what is needed to
 * decide: how long each file is, how big, what it was called, and — for an
 * acoustic finding — how much the audio agreed and over how much of it.
 *
 * Nothing here decides anything, and nothing here is authorisation. `actions`
 * is presentation; the route middleware is the permission check.
 */
final readonly class DuplicateReviewQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = DuplicateRelation::query()->with(['asset', 'relatedAsset']);

        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('detected_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,

            // DUP-001. How much is left, whatever this page is filtered to.
            // A cursor-paginated list has no total by design — it is what
            // makes it cheap over hundreds of thousands of rows — so a
            // reviewer had no way to tell whether the backlog was shrinking.
            // One count of the undecided, deliberately not a count of the
            // filtered page: what a person wants to know between decisions is
            // how many are still theirs to make.
            'open' => $this->openCount(),
        ];
    }

    /**
     * Findings nobody has answered yet.
     *
     * Unfiltered on purpose. Narrowing to `level` would make the number change
     * as somebody browses, which is the opposite of what it is for.
     */
    public function openCount(): int
    {
        return DuplicateRelation::query()
            ->where('decision', DuplicateDecision::Proposed->value)
            ->count();
    }

    /**
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'decision' => array_map(
                static fn (DuplicateDecision $case): string => $case->value,
                DuplicateDecision::cases(),
            ),
            'level' => array_map(
                static fn (DuplicateLevel $case): string => $case->value,
                DuplicateLevel::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['detected_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, DuplicateRelation>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $relation) {
            $rows[] = [
                'uuid' => $relation->uuid,
                'level' => $relation->level->value,
                'basis' => $relation->basis->value,
                'decision' => $relation->decision->value,

                // Whether the platform observed this or inferred it, sent
                // explicitly rather than left to be re-derived from the level
                // in the bundle. ADR-0016's distinction is the one thing the
                // screen must not get wrong.
                'is_measured' => $relation->level->isMeasured(),

                // Null for a checksum finding, because no acoustic comparison
                // happened. Sending 1.0 would put a figure on the screen that
                // no measurement produced.
                'similarity' => $relation->similarity(),
                'overlap_frames' => $relation->overlap_frames,
                'algorithm' => $relation->algorithm,

                'detected_at' => $relation->detected_at->toAtomString(),
                'decided_at' => $relation->decided_at?->toAtomString(),

                'asset' => $this->side($relation->asset),
                'related_asset' => $this->side($relation->relatedAsset),
            ];
        }

        return $rows;
    }

    /**
     * One side of a finding, as much as a reviewer needs to choose between them.
     *
     * **No path, no disk, no signed URL — and no original filename.** The same
     * rule {@see AssetIndexQuery} states: a storage layout published in page
     * source is one that can no longer be changed, and a durable link to a
     * master is a leaked master. The filename is on that list because it is the
     * field that most looks like a title and is not one; ING-001 settled that a
     * filename is metadata and never identity, and a reviewer who picks the
     * copy to keep by its name is deciding on the least reliable thing about
     * it.
     *
     * What is left is what actually distinguishes two files: the checksum, the
     * size, the length, and which arrived first. For an exact duplicate those
     * bytes are identical by definition, so the arrival order is the answer —
     * and it is the one fact a filename would have obscured.
     *
     * @return array<string, mixed>|null
     */
    private function side(?Asset $asset): ?array
    {
        if (! $asset instanceof Asset) {
            return null;
        }

        return [
            'uuid' => $asset->uuid,
            'byte_size' => $asset->byte_size,
            'duration_ms' => $asset->duration_ms,
            'mime_type' => $asset->mime_type,
            // Twelve characters: enough to compare two rows by eye, and the
            // full digest belongs in an export rather than on a list.
            'checksum_short' => substr((string) $asset->sha256, 0, 12),
            'status' => $asset->status->value,
            'is_trashed' => $asset->isTrashed(),
            'created_at' => $asset->created_at?->toAtomString(),
        ];
    }

    /**
     * @param  Builder<DuplicateRelation>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $decision = $filters['decision'] ?? null;

        if (is_string($decision) && $decision !== '') {
            $query->where('decision', DuplicateDecision::from($decision)->value);
        }

        $level = $filters['level'] ?? null;

        if (is_string($level) && $level !== '') {
            $query->where('level', DuplicateLevel::from($level)->value);
        }
    }

    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        return self::describesThisOrdering($cursor) ? $cursor : null;
    }
}
