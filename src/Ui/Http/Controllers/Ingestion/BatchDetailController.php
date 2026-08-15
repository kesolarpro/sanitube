<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ui\Http\Requests\IngestionBatchDetailRequest;
use SaniTube\Ui\Queries\IngestionBatchDetailQuery;

/**
 * One import operation and the items inside it. Bound by UUID.
 */
final class BatchDetailController
{
    public function __invoke(
        IngestionBatchDetailRequest $request,
        IngestionBatch $batch,
        IngestionBatchDetailQuery $detail,
    ): Response {
        return Inertia::render('Ingestion/Batches/Show', $detail->forBatch($batch, $request->cursor()));
    }
}
