<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\TrackIndexRequest;
use SaniTube\Ui\Queries\TrackIndexQuery;

/**
 * The track list.
 *
 * Filters are validated before they reach the read model, and the form is
 * re-rendered from the *validated* values rather than from the raw query
 * string. Echoing back what was typed is how a list ends up displaying a filter
 * it never applied.
 */
final class TrackIndexController
{
    public function __invoke(TrackIndexRequest $request, TrackIndexQuery $tracks): Response
    {
        $filters = $request->filters();

        return Inertia::render('Catalog/Tracks/Index', [
            'page' => $tracks->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $tracks->options(),
        ]);
    }

    /**
     * The filters as the form renders them: strings, or null for "no preference".
     *
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
