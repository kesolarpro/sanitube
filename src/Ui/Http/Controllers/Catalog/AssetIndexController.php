<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\AssetIndexRequest;
use SaniTube\Ui\Queries\AssetIndexQuery;

final class AssetIndexController
{
    public function __invoke(AssetIndexRequest $request, AssetIndexQuery $assets): Response
    {
        $filters = $request->filters();

        return Inertia::render('Catalog/Assets/Index', [
            'page' => $assets->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $assets->options(),
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
