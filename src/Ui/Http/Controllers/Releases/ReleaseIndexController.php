<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\Releases\ReleaseIndexRequest;
use SaniTube\Ui\Queries\ReleaseIndexQuery;

final class ReleaseIndexController
{
    public function __invoke(ReleaseIndexRequest $request, ReleaseIndexQuery $releases): Response
    {
        $filters = $request->filters();

        return Inertia::render('Releases/Index', [
            'page' => $releases->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $releases->options(),
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
