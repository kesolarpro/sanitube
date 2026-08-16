<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Ui\Http\Requests\Distribution\DeliveryIndexRequest;
use SaniTube\Ui\Queries\DeliveryIndexQuery;

final class DeliveryIndexController
{
    public function __invoke(
        DeliveryIndexRequest $request,
        DeliveryIndexQuery $deliveries,
        DistributorManager $distributors,
    ): Response {
        $filters = $request->filters();

        return Inertia::render('Distribution/Index', [
            'page' => $deliveries->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $deliveries->options($distributors->names()),
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
