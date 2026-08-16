<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\StoreGenerationProjectRequest;
use SaniTube\Api\Http\Resources\V1\GenerationProjectResource;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Services\CreateGenerationProject;
use Symfony\Component\HttpFoundation\Response;

final class GenerationProjectController
{
    public function index(): AnonymousResourceCollection
    {
        return GenerationProjectResource::collection(
            GenerationProject::query()->orderBy('id')->cursorPaginate()->withQueryString(),
        );
    }

    public function show(GenerationProject $project): GenerationProjectResource
    {
        return new GenerationProjectResource($project);
    }

    public function store(StoreGenerationProjectRequest $request, CreateGenerationProject $creator): JsonResponse
    {
        // The rule that a campaign starts as a draft lives in the service, so
        // this surface and the interface cannot disagree about it.
        $project = $creator->handle(
            name: (string) $request->input('name'),
            targetTrackCount: $request->integer('target_track_count') ?: null,
            defaultLanguage: $request->input('default_language'),
            defaultGenre: $request->input('default_genre'),
            defaultStylePrompt: $request->input('default_style_prompt'),
        );

        return GenerationProjectResource::make($project)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
