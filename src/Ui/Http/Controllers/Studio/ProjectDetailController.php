<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\Ui\Http\Requests\GenerationIndexRequest;
use SaniTube\Ui\Queries\GenerationIndexQuery;

/**
 * One campaign and the generations inside it.
 *
 * The generation list is the same read model the studio-wide list uses,
 * narrowed by project. Two lists rendering the same rows two different ways is
 * how one of them quietly stops matching the other.
 */
final class ProjectDetailController
{
    public function __invoke(
        GenerationIndexRequest $request,
        GenerationProject $project,
        GenerationIndexQuery $generations,
    ): Response {
        $filters = $request->filters();
        $filters['project_id'] = $project->getKey();

        return Inertia::render('Studio/Projects/Show', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'status' => $project->status->value,
                'target_track_count' => $project->target_track_count,
                'default_language' => $project->default_language,
                'default_genre' => $project->default_genre,
                'default_style_prompt' => $project->default_style_prompt,
                'accepts_generations' => $project->status->acceptsGenerations(),
                'created_at' => $project->created_at?->toAtomString(),
            ],
            'page' => $generations->paginate($filters, $request->cursor()),
        ]);
    }
}
