<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\CompositionIndexRequest;
use SaniTube\Ui\Queries\CompositionIndexQuery;

final class CompositionIndexController
{
    public function __invoke(CompositionIndexRequest $request, CompositionIndexQuery $query): Response
    {
        $filters = $request->filters();

        return Inertia::render('Catalog/Compositions/Index', [
            'page' => $query->paginate($filters, $request->cursor()),
            // The validated, canonical values — never the raw query string.
            'filters' => $this->forForm($filters),
            'options' => $query->options(),
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
