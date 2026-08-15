<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\StoreMusicGenerationRequest;
use SaniTube\Api\Http\Resources\V1\MusicGenerationResource;
use SaniTube\Api\Http\Resources\V1\MusicGenerationResultResource;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\Jobs\ImportGenerationResultJob;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Studio's write surface.
 *
 * Every route here creates or moves a *generation* — never a Track. Selecting
 * a result produces a candidate, which CAT-001 promotes or does not, so a
 * campaign cannot write into the catalogue on its own.
 */
final class MusicGenerationController
{
    public function store(StoreMusicGenerationRequest $request, StartMusicGeneration $starter): JsonResponse
    {
        $project = null;

        if (is_string($request->input('project'))) {
            $project = GenerationProject::query()->where('uuid', $request->input('project'))->first();
        }

        try {
            $generation = $starter->handle(
                prompt: (string) $request->input('prompt'),
                project: $project,
                stylePrompt: $request->input('style_prompt'),
                lyrics: $request->input('lyrics'),
                instrumental: $request->boolean('instrumental'),
                languageCode: $request->input('language_code'),
                model: $request->input('model'),
                parameters: (array) $request->input('parameters', []),
                providerName: $request->input('provider'),
            );
        } catch (GenerationException $exception) {
            return $this->refused($exception->getMessage(), $exception->reason);
        } catch (UnknownGenerationProvider $exception) {
            return $this->refused($exception->getMessage(), 'UNKNOWN_PROVIDER');
        }

        SubmitMusicGenerationJob::dispatch($generation->uuid);

        // 202: the generation is recorded, the audio does not exist yet.
        return MusicGenerationResource::make($generation)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(MusicGeneration $generation): MusicGenerationResource
    {
        return new MusicGenerationResource($generation->load(['project', 'results', 'termsSnapshot']));
    }

    public function results(MusicGeneration $generation): AnonymousResourceCollection
    {
        return MusicGenerationResultResource::collection(
            $generation->results()->orderBy('id')->get(),
        );
    }

    public function cancel(MusicGeneration $generation, MusicGenerationManager $providers): JsonResponse|MusicGenerationResource
    {
        if (! $generation->status->isCancellable()) {
            return $this->refused(
                sprintf('A generation in [%s] has already finished.', $generation->status->value),
                'NOT_CANCELLABLE',
            );
        }

        if ($generation->provider_job_id !== null) {
            // Best effort. A provider that refuses, or does not support
            // cancellation, does not stop SaniTube recording the intent —
            // otherwise a stuck job could never be closed locally.
            $providers->provider($generation->provider)->cancelGeneration($generation->provider_job_id);
        }

        $generation->forceFill([
            'status' => MusicGenerationStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        return new MusicGenerationResource($generation->refresh());
    }

    public function select(MusicGenerationResult $result): JsonResponse|MusicGenerationResultResource
    {
        if ($result->generation->status !== MusicGenerationStatus::Completed) {
            return $this->refused(
                sprintf('Results cannot be selected while the generation is [%s].', $result->generation->status->value),
                'GENERATION_NOT_COMPLETE',
            );
        }

        if (! $result->hasAudio()) {
            return $this->refused(
                'That result carries no audio to import.',
                'RESULT_HAS_NO_AUDIO',
            );
        }

        // Flagged now so the Studio reflects the choice immediately; the audio
        // is fetched by a worker, because a rendered master will not survive
        // an HTTP request.
        $result->forceFill(['selected' => true])->save();

        ImportGenerationResultJob::dispatch($result->uuid);

        return new MusicGenerationResultResource($result->refresh());
    }

    private function refused(string $message, string $code): JsonResponse
    {
        return response()->json(
            ['message' => $message, 'code' => $code],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
