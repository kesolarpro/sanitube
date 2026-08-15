<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;

/**
 * The track list: search, filters, and a page of rows.
 *
 * Three decisions shape it.
 *
 * **Cursor pagination, ordered by `(title, uuid)`.** Offset pagination on a
 * catalogue that is being written to shows the same row twice and skips
 * another, which on an ingestion screen means an operator reviews one candidate
 * twice and never sees a third. The tiebreaker is the UUID rather than the
 * primary key on purpose: Laravel encodes the ordering columns into the cursor
 * string, and that string travels in the URL, so ordering by `id` would publish
 * internal keys in every "next page" link.
 *
 * **Eager loading is not optional here.** A hundred rows each rendering its
 * artists and its ISRC is three hundred queries if the relations are loaded
 * lazily. They are loaded in three, and `TrackIndexQueryTest` asserts the count
 * does not move when the page gets longer.
 *
 * **Nothing about storage leaves this class.** A row carries a title, artists,
 * a duration and an ISRC. It does not carry a disk, a path, a bucket or a
 * numeric id, because a list endpoint is the easiest place for those to escape
 * and the hardest place to notice they have.
 */
final readonly class TrackIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $query = Track::query()
            ->with([
                'artists',
                'externalIdentifiers' => fn (Relation $relation) => $relation->where('type', ExternalIdentifierType::Isrc->value),
            ]);

        $this->applySearch($query, $this->stringOrNull($filters['search'] ?? null));
        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('title')
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
     * The filter vocabulary, so the interface never invents an option the query
     * does not understand.
     *
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'status' => array_map(fn (TrackStatus $case): string => $case->value, TrackStatus::cases()),
            'source' => array_map(fn (TrackSource $case): string => $case->value, TrackSource::cases()),
        ];
    }

    /**
     * @param  CursorPaginator<int, Track>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $track) {
            $rows[] = [
                'uuid' => $track->uuid,
                'title' => $track->title,
                'version_title' => $track->version_title,
                'artists' => $track->artists
                    ->map(fn (Artist $artist): array => ['uuid' => $artist->uuid, 'name' => $artist->name])
                    ->values()
                    ->all(),
                'status' => $track->status->value,
                'source' => $track->source->value,
                'language_code' => $track->language_code,
                'duration_ms' => $track->duration_ms,
                'is_instrumental' => $track->is_instrumental,
                'is_explicit' => $track->is_explicit,
                'isrc' => $track->externalIdentifiers->first()?->value,
                'updated_at' => $track->updated_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * Title, ISRC or artist name.
     *
     * Deliberately three `LIKE`s rather than a full-text index: MySQL, MariaDB
     * and SQLite disagree about full-text syntax and about which storage
     * engines support it at all, and this platform promises to run on every one
     * of them. At the size SaniTube is built for — a catalogue in the
     * thousands — a prefix-anchored LIKE on an indexed column is not the
     * bottleneck. If it ever becomes one, that is a decision to make with a
     * measurement, not in advance.
     *
     * @param  Builder<Track>  $query
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
                ->orWhereHas(
                    'externalIdentifiers',
                    fn (Builder $relation) => $relation
                        ->where('type', ExternalIdentifierType::Isrc->value)
                        ->where('value', 'like', $term),
                )
                ->orWhereHas('artists', fn (Builder $relation) => $relation->where('name', 'like', $term));
        });
    }

    /**
     * @param  Builder<Track>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Each filter is matched against the enum rather than passed through.
        // An unrecognised value narrows nothing instead of reaching the
        // database, so a hand-edited query string cannot become a SQL surprise
        // or, worse, silently return everything while looking filtered.
        $status = $this->stringOrNull($filters['status'] ?? null);

        if ($status !== null && TrackStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $source = $this->stringOrNull($filters['source'] ?? null);

        if ($source !== null && TrackSource::tryFrom($source) !== null) {
            $query->where('source', $source);
        }

        $language = $this->stringOrNull($filters['language'] ?? null);

        if ($language !== null) {
            $query->where('language_code', $language);
        }

        foreach (['instrumental' => 'is_instrumental', 'explicit' => 'is_explicit'] as $filter => $column) {
            $value = $filters[$filter] ?? null;

            if ($value === '1' || $value === true) {
                $query->where($column, true);
            } elseif ($value === '0' || $value === false) {
                $query->where($column, false);
            }
        }

        $artist = $this->stringOrNull($filters['artist'] ?? null);

        if ($artist !== null) {
            // By UUID: the interface never holds an internal key, so it cannot
            // filter by one.
            $query->whereHas('artists', fn (Builder $relation) => $relation->where('uuid', $artist));
        }

        $role = $this->stringOrNull($filters['artist_role'] ?? null);

        if ($artist !== null && $role !== null && TrackArtistRole::tryFrom($role) !== null) {
            // The pivot table by name: inside `whereHas` the builder is a
            // plain query over artists joined to the pivot, so `wherePivot` —
            // which belongs to the relation object — is not available.
            $query->whereHas(
                'artists',
                fn (Builder $relation) => $relation->where('uuid', $artist)->whereRaw('track_artist.role = ?', [$role]),
            );
        }
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
