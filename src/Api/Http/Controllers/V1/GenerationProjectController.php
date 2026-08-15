<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\StoreGenerationProjectRequest;
use SaniTube\Api\Http\Resources\V1\GenerationProjectResource;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Models\GenerationProject;
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

    public function store(StoreGenerationProjectRequest $request): JsonResponse
    {
        $project = GenerationProject::query()->create([
            'name' => (string) $request->input('name'),
            'target_track_count' => $request->input('target_track_count'),
            'default_language' => $request->input('default_language'),
            'default_genre' => $request->input('default_genre'),
            'default_style_prompt' => $request->input('default_style_prompt'),
            // A campaign starts as a draft. Nothing has been generated, and
            // ACTIVE would claim otherwise.
            'status' => GenerationProjectStatus::Draft,
        ]);

        return GenerationProjectResource::make($project)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
