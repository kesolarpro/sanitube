<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;

/**
 * The list of releases.
 *
 * Ordered by `(created_at, uuid)` like every other list here. Not by release
 * date: a label assembling a back catalogue enters records whose dates are
 * years apart and in no particular order, and a list that reshuffles as dates
 * are filled in is one nobody can work down.
 *
 * `track_count` and `artist_count` come from one `withCount` per page rather
 * than a query per row. Counting per row is invisible until somebody has a few
 * hundred releases, which is the point at which a back catalogue becomes worth
 * managing.
 */
final readonly class ReleaseIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = Release::query()->withCount(['tracks', 'artists']);

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
                static fn (ReleaseStatus $case): string => $case->value,
                ReleaseStatus::cases(),
            ),
            'type' => array_map(
                static fn (ReleaseType $case): string => $case->value,
                ReleaseType::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, Release>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $release) {
            $rows[] = [
                'uuid' => $release->uuid,
                'title' => $release->title,
                'version_title' => $release->version_title,
                'type' => $release->type->value,
                'status' => $release->status->value,
                'label_name' => $release->label_name,
                // Null stays null. A release with no date has none, and a
                // placeholder would be indistinguishable from a real one.
                'release_date' => $release->release_date?->toDateString(),
                'track_count' => (int) ($release->tracks_count ?? 0),
                'artist_count' => (int) ($release->artists_count ?? 0),
                'has_cover' => $release->cover_asset_id !== null,
                'created_at' => $release->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Release>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', ReleaseStatus::from($status)->value);
        }

        $type = $filters['type'] ?? null;

        if (is_string($type) && $type !== '') {
            $query->where('type', ReleaseType::from($type)->value);
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
