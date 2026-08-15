<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Models\GenerationProject;

/**
 * Generation projects — campaigns, in the domain's own words: "forty ambient
 * instrumentals for the autumn catalogue".
 *
 * `target_track_count` is nullable and stays nullable all the way to the
 * screen. A project with no target has none, and rendering 0 would say
 * somebody set a target of nothing.
 */
final readonly class GenerationProjectIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public function paginate(?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $page = GenerationProject::query()
            ->withCount('generations')
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
                static fn (GenerationProjectStatus $case): string => $case->value,
                GenerationProjectStatus::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, GenerationProject>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $project) {
            $rows[] = [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'status' => $project->status->value,
                'target_track_count' => $project->target_track_count,
                'generation_count' => (int) ($project->generations_count ?? 0),
                'default_language' => $project->default_language,
                'default_genre' => $project->default_genre,
                'accepts_generations' => $project->status->acceptsGenerations(),
                'created_at' => $project->created_at?->toAtomString(),
            ];
        }

        return $rows;
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
