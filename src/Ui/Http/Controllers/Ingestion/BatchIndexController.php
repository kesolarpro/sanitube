<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\IngestionBatchIndexRequest;
use SaniTube\Ui\Queries\IngestionBatchIndexQuery;

/**
 * Every import operation, most recent last.
 */
final class BatchIndexController
{
    public function __invoke(IngestionBatchIndexRequest $request, IngestionBatchIndexQuery $batches): Response
    {
        $filters = $request->filters();

        return Inertia::render('Ingestion/Batches/Index', [
            'page' => $batches->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $batches->options(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string|null>
     */
    private function forForm(array $filters): array
    {
        $form = [];

        foreach ($filters as $key => $value) {
            $form[$key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_string($value) => $value,
                default => null,
            };
        }

        return $form;
    }
}
