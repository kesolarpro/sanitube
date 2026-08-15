<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SaniTube\Api\Http\Requests\V1\IngestionBatchIndexRequest;
use SaniTube\Api\Http\Requests\V1\StoreIngestionBatchRequest;
use SaniTube\Api\Http\Resources\V1\IngestionBatchResource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Services\StartIngestionBatch;

final class IngestionBatchController
{
    public function index(IngestionBatchIndexRequest $request): AnonymousResourceCollection
    {
        $batches = IngestionBatch::query()
            ->when($request->filters()['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($request->filters()['source'] ?? null, fn (Builder $q, string $source): Builder => $q->where('source', $source))
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return IngestionBatchResource::collection($batches);
    }

    public function show(IngestionBatch $batch): IngestionBatchResource
    {
        return new IngestionBatchResource($batch);
    }

    /**
     * Start an import.
     *
     * A refusal here is a 422 carrying the reason. The refusals are all of the
     * same kind — this prefix holds the platform's own output, this batch is
     * larger than a person could review, this source is declared but not
     * implemented — and every one of them is something the caller can fix,
     * which is what separates it from a 500.
     */
    public function store(StoreIngestionBatchRequest $request, StartIngestionBatch $starter): JsonResponse
    {
        try {
            $batch = $starter->handle(
                source: $request->source(),
                prefix: $request->prefix(),
                references: $request->references(),
                provider: $request->provider(),
            );
        } catch (IngestionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->failureCode->value,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new IngestionBatchResource($batch))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_ACCEPTED);
    }
}
