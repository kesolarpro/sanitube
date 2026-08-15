<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\ReleaseIndexRequest;
use SaniTube\Api\Http\Resources\V1\ReleaseResource;
use SaniTube\Releases\Models\Release;

final class ReleaseController
{
    public function index(ReleaseIndexRequest $request): AnonymousResourceCollection
    {
        $releases = Release::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($request->filters()['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->with('artists')
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ReleaseResource::collection($releases);
    }

    public function show(Release $release): ReleaseResource
    {
        $release->load([
            'artists',
            'coverAsset',
            'tracks',
            'externalIdentifiers' => fn (Relation $query): Relation => $query->whereNotNull('active_marker'),
        ]);

        return new ReleaseResource($release);
    }
}
