<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\TrackCandidateIndexRequest;
use SaniTube\Ui\Queries\TrackCandidateIndexQuery;

/**
 * The review queue.
 */
final class CandidateIndexController
{
    public function __invoke(TrackCandidateIndexRequest $request, TrackCandidateIndexQuery $candidates): Response
    {
        $filters = $request->filters();

        return Inertia::render('Ingestion/Candidates/Index', [
            'page' => $candidates->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $candidates->options(),
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
