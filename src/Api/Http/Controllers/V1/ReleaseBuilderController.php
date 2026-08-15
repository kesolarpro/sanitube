<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaniTube\Api\Http\Requests\V1\StoreReleaseRequest;
use SaniTube\Api\Http\Requests\V1\UpdateReleaseRequest;
use SaniTube\Api\Http\Resources\V1\ReleaseResource;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Exceptions\ReleaseBuilderException;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Releases\Services\ValidateRelease;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Release Builder's write surface.
 *
 * `validate` and `ready` are separate endpoints because they answer different
 * questions. A label wants to *see* what is missing while it is still
 * assembling; committing is a distinct act, and one that can fail.
 *
 * Nothing here sets `status` directly. Readiness is earned by passing
 * validation, not assigned.
 */
final class ReleaseBuilderController
{
    public function store(StoreReleaseRequest $request, ReleaseBuilder $builder): JsonResponse
    {
        $release = $builder->createDraft(
            title: (string) $request->input('title'),
            type: ReleaseType::tryFrom((string) $request->input('type', 'SINGLE')) ?? ReleaseType::Single,
            languageCode: $request->input('language_code'),
            versionTitle: $request->input('version_title'),
        );

        return ReleaseResource::make($release)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateReleaseRequest $request, Release $release, ReleaseBuilder $builder): JsonResponse|ReleaseResource
    {
        try {
            $updated = $builder->updateDetails($release, $request->safe()->all());
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception);
        }

        return new ReleaseResource($updated);
    }

    public function addTrack(Request $request, Release $release, ReleaseBuilder $builder): JsonResponse|ReleaseResource
    {
        $track = Track::query()->where('uuid', $request->input('track'))->first();

        if (! $track instanceof Track) {
            return response()->json(
                ['message' => 'No such track.', 'code' => 'TRACK_NOT_FOUND'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $updated = $builder->addTrack(
                $release,
                $track,
                disc: max(1, (int) $request->input('disc', 1)),
                isFocusTrack: (bool) $request->boolean('is_focus_track'),
            );
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception);
        }

        return new ReleaseResource($updated->load('tracks'));
    }

    public function removeTrack(Release $release, Track $track, ReleaseBuilder $builder): JsonResponse|ReleaseResource
    {
        try {
            $updated = $builder->removeTrack($release, $track);
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception);
        }

        return new ReleaseResource($updated->load('tracks'));
    }

    public function reorder(Request $request, Release $release, ReleaseBuilder $builder): JsonResponse|ReleaseResource
    {
        /** @var list<string> $order */
        $order = array_values(array_filter((array) $request->input('tracks', []), is_string(...)));

        try {
            $updated = $builder->reorder($release, $order, disc: max(1, (int) $request->input('disc', 1)));
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception);
        }

        return new ReleaseResource($updated->load('tracks'));
    }

    /**
     * What is wrong, at any point, without committing to anything.
     */
    public function validateRelease(Release $release, ValidateRelease $validator): JsonResponse
    {
        return response()->json(['data' => $validator->handle($release)->toArray()]);
    }

    public function ready(Release $release, ValidateRelease $validator): JsonResponse|ReleaseResource
    {
        $result = $validator->handle($release);

        if (! $result->isValid()) {
            return response()->json([
                'message' => 'This release is not ready.',
                'code' => 'RELEASE_NOT_READY',
                'data' => $result->toArray(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // I4 is enforced by the model, not by this controller. Validation
            // above is what a caller sees; markReady() is what is true.
            $release->markReady();
        } catch (ReleaseNotReadyException $exception) {
            return response()->json(
                ['message' => $exception->getMessage(), 'code' => 'RELEASE_NOT_READY'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new ReleaseResource($release->refresh());
    }

    public function reopen(Release $release, ReleaseBuilder $builder): JsonResponse|ReleaseResource
    {
        try {
            return new ReleaseResource($builder->reopen($release));
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception);
        }
    }

    private function refused(ReleaseBuilderException $exception): JsonResponse
    {
        return response()->json(
            ['message' => $exception->getMessage(), 'code' => $exception->reason],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
