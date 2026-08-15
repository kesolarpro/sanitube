<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use SaniTube\Catalog\Enums\CompositionStatus;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\ExternalIdentifier;

/**
 * The composition list.
 *
 * Ordered by `(title, uuid)`. Active ISWCs only — a revoked one is history, and
 * showing it as the work's identity is how a superseded code ends up in a
 * registration.
 */
final readonly class CompositionIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = Composition::query()
            ->withCount(['tracks', 'contributors'])
            ->with([
                'externalIdentifiers' => fn (Relation $relation) => $relation
                    ->where('type', ExternalIdentifierType::Iswc->value)
                    ->where('active_marker', ExternalIdentifier::ACTIVE)
                    ->orderByDesc('is_authoritative')
                    ->orderByDesc('assigned_at'),
            ]);

        $this->applySearch($query, $this->stringOrNull($filters['search'] ?? null));
        $this->applyFilters($query, $filters);

        $page = $query->orderBy('title')->orderBy('uuid')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

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
            'status' => array_map(fn (CompositionStatus $case): string => $case->value, CompositionStatus::cases()),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['title', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, Composition>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $composition) {
            $rows[] = [
                'uuid' => $composition->uuid,
                'title' => $composition->title,
                'alternative_title' => $composition->alternative_title,
                'language_code' => $composition->language_code,
                'created_year' => $composition->created_year,
                'is_public_domain' => $composition->is_public_domain,
                'status' => $composition->status->value,
                'iswc' => $composition->externalIdentifiers->first()?->value,
                'track_count' => (int) $composition->tracks_count,
                'contributor_count' => (int) $composition->contributors_count,
                'updated_at' => $composition->updated_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<Composition>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function (Builder $scoped) use ($term): void {
            $scoped
                ->where('title', 'like', $term)
                ->orWhere('alternative_title', 'like', $term)
                ->orWhereHas(
                    'externalIdentifiers',
                    fn (Builder $relation) => $relation
                        ->where('type', ExternalIdentifierType::Iswc->value)
                        ->where('active_marker', ExternalIdentifier::ACTIVE)
                        ->where('value', 'like', $term),
                );
        });
    }

    /**
     * @param  Builder<Composition>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $this->stringOrNull($filters['status'] ?? null);

        if ($status !== null) {
            $case = CompositionStatus::tryFrom($status);

            if ($case === null) {
                throw new InvalidArgumentException(sprintf('[%s] is not a valid status filter.', $status));
            }

            $query->where('status', $case->value);
        }

        $language = $this->stringOrNull($filters['language'] ?? null);

        if ($language !== null) {
            $query->where('language_code', $language);
        }

        $publicDomain = $filters['public_domain'] ?? null;

        if (is_bool($publicDomain)) {
            $query->where('is_public_domain', $publicDomain);
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
