<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use App\Models\User;
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

        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Ingestion/Batches/Index', [
            'page' => $batches->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $batches->options(),

            // Presentation, never authorisation: `can.role:catalogue` on the
            // route is what decides. This only lets the form be absent for a
            // MEMBER rather than refused on submit.
            'actions' => ['may_start' => $viewer->role->canWriteCatalogue()],
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
