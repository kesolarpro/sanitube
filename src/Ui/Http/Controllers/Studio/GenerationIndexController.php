<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\GenerationIndexRequest;
use SaniTube\Ui\Queries\GenerationIndexQuery;

final class GenerationIndexController
{
    public function __invoke(GenerationIndexRequest $request, GenerationIndexQuery $generations): Response
    {
        $filters = $request->filters();

        return Inertia::render('Studio/Generations/Index', [
            'page' => $generations->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $generations->options(),
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
            $form[$key] = is_string($value) ? $value : null;
        }

        return $form;
    }
}
