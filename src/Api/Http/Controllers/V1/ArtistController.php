<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\ArtistIndexRequest;
use SaniTube\Api\Http\Resources\V1\ArtistResource;
use SaniTube\Artists\Models\Artist;

final class ArtistController
{
    public function index(ArtistIndexRequest $request): AnonymousResourceCollection
    {
        $artists = Artist::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($request->filters()['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            // Ordering by the primary key rather than by name keeps the cursor
            // stable: a rename must not move a row across a page boundary and
            // make a caller skip or repeat it mid-pagination.
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ArtistResource::collection($artists);
    }

    public function show(Artist $artist): ArtistResource
    {
        return new ArtistResource($artist);
    }
}
