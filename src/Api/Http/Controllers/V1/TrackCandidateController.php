<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\TrackCandidateIndexRequest;
use SaniTube\Api\Http\Resources\V1\TrackCandidateResource;
use SaniTube\Ingestion\Models\TrackCandidate;

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
}
