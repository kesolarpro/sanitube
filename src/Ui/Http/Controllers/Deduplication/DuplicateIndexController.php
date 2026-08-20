<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Deduplication;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\Deduplication\DuplicateIndexRequest;
use SaniTube\Ui\Http\Requests\Deduplication\TrashAssetRequest;
use SaniTube\Ui\Queries\DuplicateReviewQuery;

/**
 * The duplicate queue.
 */
final class DuplicateIndexController
{
    public function __invoke(DuplicateIndexRequest $request, DuplicateReviewQuery $duplicates): Response
    {
        $filters = $request->filters();

        return Inertia::render('Deduplication/Index', [
            'page' => $duplicates->paginate($filters, $request->cursor()),
            'filters' => $filters,
            'options' => [
                ...$duplicates->options(),
                // Sent from the one list the validator enforces, so the picker
                // and the rule can never drift apart.
                'trash_reason' => TrashAssetRequest::REASONS,
            ],
        ]);
    }
}
