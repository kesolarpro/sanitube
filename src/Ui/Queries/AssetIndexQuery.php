<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;

/**
 * The asset list.
 *
 * Ordered by `(created_at, uuid)`. Assets have no title, and the two fields
 * that would read like one — `original_filename` and `path` — are exactly the
 * fields this interface must not expose. Ordering by either would publish them
 * inside the cursor, which travels in the URL, so the ordering key is when the
 * asset entered the system.
 *
 * Nothing about where an asset lives leaves this class: no disk, no path, no
 * bucket, no original filename, no internal key.
 */
final readonly class AssetIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = Asset::query();

        $this->applyFilters($query, $filters);

        $page = $query->orderBy('created_at')->orderBy('uuid')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

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
            'kind' => array_map(fn (AssetKind $case): string => $case->value, AssetKind::cases()),
            'status' => array_map(fn (AssetStatus $case): string => $case->value, AssetStatus::cases()),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, Asset>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $asset) {
            $rows[] = [
                'uuid' => $asset->uuid,
                'kind' => $asset->kind->value,
                'status' => $asset->status->value,
                'mime_type' => $asset->mime_type,
                'byte_size' => $asset->byte_size,
                // Twelve characters: enough to compare two rows by eye, and the
                // full digest belongs in an export rather than on a list.
                'checksum_short' => substr((string) $asset->sha256, 0, 12),
                'duration_ms' => $asset->duration_ms,
                'is_duplicate' => $asset->duplicate_of_asset_id !== null,
                'is_derivative' => $asset->parent_asset_id !== null,
                'is_trashed' => $asset->isTrashed(),
                'trashed_at' => $asset->trashed_at?->toAtomString(),
                'trash_reason' => $asset->trash_reason,
                'stored_at' => $asset->stored_at?->toAtomString(),
                'verified_at' => $asset->verified_at?->toAtomString(),
                'created_at' => $asset->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $kind = $this->stringOrNull($filters['kind'] ?? null);

        if ($kind !== null) {
            $case = AssetKind::tryFrom($kind);

            if ($case === null) {
                throw new InvalidArgumentException(sprintf('[%s] is not a valid kind filter.', $kind));
            }

            $query->where('kind', $case->value);
        }

        $status = $this->stringOrNull($filters['status'] ?? null);

        if ($status !== null) {
            $case = AssetStatus::tryFrom($status);

            if ($case === null) {
                throw new InvalidArgumentException(sprintf('[%s] is not a valid status filter.', $status));
            }

            $query->where('status', $case->value);
        }

        $duplicates = $filters['duplicates'] ?? null;

        if (is_bool($duplicates)) {
            $duplicates
                ? $query->whereNotNull('duplicate_of_asset_id')
                : $query->whereNull('duplicate_of_asset_id');
        }

        // Trash is hidden unless it is asked for. Setting something aside that
        // then goes on appearing in every listing is not setting it aside --
        // and the list still has to be reachable, because an asset nobody can
        // find is one nobody can restore.
        match ($this->stringOrNull($filters['trashed'] ?? null)) {
            'only' => $query->whereNotNull('trashed_at'),
            'all' => null,
            default => $query->whereNull('trashed_at'),
        };
    }

    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! self::describesThisOrdering($cursor)) {
            throw new InvalidArgumentException('The pagination cursor is not one this list issued.');
        }

        return $cursor;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
