<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\PromoteTrackCandidateRequest;
use SaniTube\Api\Http\Requests\V1\RejectTrackCandidateRequest;
use SaniTube\Api\Http\Requests\V1\ReviseTrackCandidateRequest;
use SaniTube\Api\Http\Requests\V1\TrackCandidateIndexRequest;
use SaniTube\Api\Http\Resources\V1\TrackCandidateResource;
use SaniTube\Api\Http\Resources\V1\TrackResource;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\RejectTrackCandidate;
use SaniTube\Ingestion\Services\ReviseTrackCandidate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The candidate review surface.
 *
 * `promote` is the only endpoint in v1 that writes to the catalogue, and it is
 * a POST to a named action rather than a PATCH of `status`. Promotion runs
 * invariants, creates a Track and is refusable for six distinct reasons; a
 * status field that a client sets would make all of that look like an
 * assignment.
 */
final class TrackCandidateController
{
    public function index(TrackCandidateIndexRequest $request): AnonymousResourceCollection
    {
        $candidates = TrackCandidate::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($request->filters()['source'] ?? null, fn (Builder $q, string $source): Builder => $q->where('source', $source))
            ->with('asset')
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TrackCandidateResource::collection($candidates);
    }

    public function show(TrackCandidate $candidate): TrackCandidateResource
    {
        $candidate->load(['asset', 'matchedAsset', 'promotedTrack']);

        return new TrackCandidateResource($candidate);
    }

    public function update(
        ReviseTrackCandidateRequest $request,
        TrackCandidate $candidate,
        ReviseTrackCandidate $reviser,
    ): TrackCandidateResource|JsonResponse {
        try {
            $revised = $reviser->handle($candidate, $request->revisions());
        } catch (CandidateReviewException $exception) {
            return $this->refused($exception);
        }

        return new TrackCandidateResource($revised->load('asset'));
    }

    public function promote(
        PromoteTrackCandidateRequest $request,
        TrackCandidate $candidate,
        PromoteTrackCandidate $promoter,
    ): JsonResponse {
        try {
            $track = $promoter->handle(
                candidate: $candidate,
                attributes: $request->catalogueAttributes(),
                override: $request->override(),
                note: $request->note(),
            );
        } catch (CandidateReviewException $exception) {
            return $this->refused($exception);
        }

        // 201: unlike starting an import, the thing this request describes now
        // exists. The Track is returned rather than the candidate, because the
        // Track is what the caller asked to bring into being.
        return TrackResource::make($track->load('masterAsset'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function reject(
        RejectTrackCandidateRequest $request,
        TrackCandidate $candidate,
        RejectTrackCandidate $rejecter,
    ): TrackCandidateResource|JsonResponse {
        try {
            $rejected = $rejecter->handle($candidate, $request->reason());
        } catch (CandidateReviewException $exception) {
            return $this->refused($exception);
        }

        return new TrackCandidateResource($rejected->load('asset'));
    }

    /**
     * A refusal the caller can act on, carrying a machine-readable reason.
     *
     * 422 rather than 409: every one of these is something about the request
     * or the candidate that the caller can change — supply a note, promote a
     * different candidate, wait for analysis to finish.
     */
    private function refused(CandidateReviewException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->reason,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
