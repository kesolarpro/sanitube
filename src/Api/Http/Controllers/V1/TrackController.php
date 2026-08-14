<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\TrackIndexRequest;
use SaniTube\Api\Http\Resources\V1\TrackResource;
use SaniTube\Catalog\Models\Track;

final class TrackController
{
    public function index(TrackIndexRequest $request): AnonymousResourceCollection
    {
        $tracks = Track::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($request->filters()['source'] ?? null, fn (Builder $query, string $source): Builder => $query->where('source', $source))
            ->with('artists')
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TrackResource::collection($tracks);
    }

    public function show(Track $track): TrackResource
    {
        $track->load([
            'artists',
            'composition',
            'masterAsset',
            'externalIdentifiers' => fn (Relation $query): Relation => $query->whereNotNull('active_marker'),
        ]);

        return new TrackResource($track);
    }
}
