<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Contributors\Enums\ContributorType;
use SaniTube\Contributors\Models\Contributor;

/**
 * The contributor list.
 *
 * Same shape as {@see ArtistIndexQuery}: cursor pagination on a natural key
 * plus the UUID, filters refused rather than silently discarded, and counts
 * aggregated in the list query rather than asked per row.
 *
 * Ordered by `(legal_name, uuid)`. Legal name rather than display name because
 * display name is nullable, and an ordering column that can be null is an
 * ordering that puts an arbitrary subset of the list in an arbitrary place.
 */
final readonly class ContributorIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = Contributor::query()->withCount(['compositions', 'tracks']);

        $this->applySearch($query, $this->stringOrNull($filters['search'] ?? null));
        $this->applyFilters($query, $filters);

        $page = $query->orderBy('legal_name')->orderBy('uuid')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

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
            'type' => array_map(fn (ContributorType $case): string => $case->value, ContributorType::cases()),
        ];
    }

    /**
     * Whether an encoded cursor carries the parameters this query orders by.
     *
     * Without the check a mangled URL reaches `UnexpectedValueException` inside
     * the paginator and becomes a 500 for what is at worst a bad link.
     */
    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['legal_name', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, Contributor>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $contributor) {
            $rows[] = [
                'uuid' => $contributor->uuid,
                // The name a screen should show, with the legal name as the
                // fallback rather than a blank cell.
                'name' => $contributor->display_name ?? $contributor->legal_name,
                'has_distinct_legal_name' => $contributor->display_name !== null
                    && $contributor->display_name !== $contributor->legal_name,
                'type' => $contributor->type->value,
                'country' => $contributor->country,
                'composition_count' => (int) $contributor->compositions_count,
                'track_count' => (int) $contributor->tracks_count,
                'updated_at' => $contributor->updated_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Contributor>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        // Contains, not prefix-anchored, and wildcards escaped so searching for
        // `%` matches the character rather than returning the whole list.
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function (Builder $scoped) use ($term): void {
            $scoped->where('legal_name', 'like', $term)->orWhere('display_name', 'like', $term);
        });
    }

    /**
     * @param  Builder<Contributor>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $type = $this->stringOrNull($filters['type'] ?? null);

        if ($type !== null) {
            $case = ContributorType::tryFrom($type);

            if ($case === null) {
                throw new InvalidArgumentException(sprintf('[%s] is not a valid type filter.', $type));
            }

            $query->where('type', $case->value);
        }

        $country = $this->stringOrNull($filters['country'] ?? null);

        if ($country !== null) {
            $query->where('country', strtoupper($country));
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

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
