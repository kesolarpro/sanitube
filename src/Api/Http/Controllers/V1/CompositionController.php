<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\CompositionIndexRequest;
use SaniTube\Api\Http\Resources\V1\CompositionResource;
use SaniTube\Catalog\Models\Composition;

final class CompositionController
{
    public function index(CompositionIndexRequest $request): AnonymousResourceCollection
    {
        $compositions = Composition::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return CompositionResource::collection($compositions);
    }

    public function show(Composition $composition): CompositionResource
    {
        $composition->load([
            'contributorCredits.contributor',
            // Revoked identifiers stay out of the standard listings: a
            // consumer reading this endpoint wants what is in force now. An
            // audit endpoint will expose the history.
            'externalIdentifiers' => fn (Relation $query): Relation => $query->whereNotNull('active_marker'),
        ]);

        return new CompositionResource($composition);
    }
}
