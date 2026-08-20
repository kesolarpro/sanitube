<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Production;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\ProductionPlanIndexRequest;
use SaniTube\Ui\Queries\ProductionPlanIndexQuery;

/**
 * What the platform has been told to do on its own.
 *
 * A read, available to anybody who may sign in. Watching what an autonomous
 * planner did is not a privilege — being unable to watch it is how an operator
 * discovers a month of unwanted generations from a supplier's bill.
 */
final class PlanIndexController
{
    public function __invoke(ProductionPlanIndexRequest $request, ProductionPlanIndexQuery $plans): Response
    {
        return Inertia::render('Production/Index', [
            'page' => $plans->paginate($request->cursor()),
            'options' => $plans->options(),
        ]);
    }
}
