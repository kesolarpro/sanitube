<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;

/**
 * The list of generations.
 *
 * Ordered by `(created_at, uuid)`, newest last like every other list here.
 *
 * The prompt is shown because it is the only thing that distinguishes one
 * generation from another to a person; the lyrics are not, because a list is
 * for finding the row worth opening and a verse in a table cell is neither
 * readable nor useful.
 *
 * **`provider_job_id` never leaves the server.** It is the handle by which the
 * provider's own API addresses this job, and a page is not the place to
 * publish it.
 */
final readonly class GenerationIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = MusicGeneration::query();

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
                static fn (MusicGenerationStatus $case): string => $case->value,
                MusicGenerationStatus::cases(),
            ),
            'commercial_rights_status' => array_map(
                static fn (CommercialRightsStatus $case): string => $case->value,
                CommercialRightsStatus::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, MusicGeneration>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $generation) {
            $rows[] = [
                'uuid' => $generation->uuid,
                'status' => $generation->status->value,
                'provider' => $generation->provider,
                'prompt' => $generation->prompt,
                'instrumental' => (bool) $generation->instrumental,
                'language_code' => $generation->language_code,
                'commercial_rights_status' => $generation->commercial_rights_status->value,
                'result_count' => (int) ($generation->results_count ?? 0),
                'created_at' => $generation->created_at?->toAtomString(),
                'completed_at' => $generation->completed_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<MusicGeneration>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // One count for the page rather than one per row: a list that queries
        // per row is invisible until somebody has a few hundred generations.
        $query->withCount('results');

        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', MusicGenerationStatus::from($status)->value);
        }

        $rights = $filters['commercial_rights_status'] ?? null;

        if (is_string($rights) && $rights !== '') {
            $query->where('commercial_rights_status', CommercialRightsStatus::from($rights)->value);
        }

        $project = $filters['project_id'] ?? null;

        if (is_int($project)) {
            $query->where('project_id', $project);
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
