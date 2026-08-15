<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Enums\ArtistType;
use SaniTube\Artists\Models\Artist;

/**
 * The artist list.
 *
 * Same shape as {@see TrackIndexQuery}, and deliberately so: cursor pagination
 * ordered by a natural key plus the UUID, filters refused rather than silently
 * discarded, and counts aggregated rather than fetched per row.
 *
 * The ordering is `(name, uuid)`. As with tracks, the tiebreaker is the UUID
 * rather than the primary key because Laravel encodes the ordering columns into
 * the cursor and that string travels in the URL.
 */
final readonly class ArtistIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = Artist::query()
            // Aggregated in the same query rather than counted per row. A
            // hundred artists each asking "how many tracks?" is a hundred
            // round trips for a number that fits in the SELECT.
            ->withCount(['tracks', 'releases']);

        $this->applySearch($query, $this->stringOrNull($filters['search'] ?? null));
        $this->applyFilters($query, $filters);

        $page = $query->orderBy('name')->orderBy('uuid')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

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
            'status' => array_map(fn (ArtistStatus $case): string => $case->value, ArtistStatus::cases()),
            'type' => array_map(fn (ArtistType $case): string => $case->value, ArtistType::cases()),
        ];
    }

    /**
     * Whether an encoded cursor carries the parameters this query orders by.
     *
     * Without the check a mangled URL reaches `UnexpectedValueException` inside
     * the paginator and becomes a 500.
     */
    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['name', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, Artist>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $artist) {
            $rows[] = [
                'uuid' => $artist->uuid,
                'name' => $artist->name,
                'sort_name' => $artist->sort_name,
                'type' => $artist->type->value,
                'status' => $artist->status->value,
                'country' => $artist->country,
                'is_owner_controlled' => $artist->is_owner_controlled,
                'track_count' => (int) $artist->tracks_count,
                'release_count' => (int) $artist->releases_count,
                'updated_at' => $artist->updated_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Artist>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        // Contains, not prefix-anchored: somebody looking for "Ferry" expects
        // to find "The Ferrymen". Wildcards are escaped so searching for `%`
        // matches the character rather than returning the whole list.
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function (Builder $scoped) use ($term): void {
            $scoped->where('name', 'like', $term)->orWhere('sort_name', 'like', $term);
        });
    }

    /**
     * @param  Builder<Artist>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Refused, never ignored: discarding an unrecognised value returns the
        // whole list while the caller believes a filter is applied.
        $status = $this->stringOrNull($filters['status'] ?? null);

        if ($status !== null) {
            $query->where('status', $this->enum(ArtistStatus::class, $status, 'status')->value);
        }

        $type = $this->stringOrNull($filters['type'] ?? null);

        if ($type !== null) {
            $query->where('type', $this->enum(ArtistType::class, $type, 'type')->value);
        }

        $country = $this->stringOrNull($filters['country'] ?? null);

        if ($country !== null) {
            $query->where('country', strtoupper($country));
        }

        $owned = $filters['owner_controlled'] ?? null;

        if (is_bool($owned)) {
            $query->where('is_owner_controlled', $owned);
        }
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

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $enum, string $value, string $filter): \BackedEnum
    {
        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw new InvalidArgumentException(sprintf('[%s] is not a valid %s filter.', $value, $filter));
        }

        return $case;
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
