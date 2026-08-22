<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Production;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\ProductionPlanIndexRequest;
use SaniTube\Ui\Queries\EditorialProfileIndexQuery;
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
    public function __invoke(
        ProductionPlanIndexRequest $request,
        ProductionPlanIndexQuery $plans,
        EditorialProfileIndexQuery $profiles,
    ): Response {
        return Inertia::render('Production/Index', [
            'page' => $plans->paginate($request->cursor()),
            'options' => $plans->options(),
            // PROD-002. The imprints a new plan may be pointed at. Active ones
            // only, because the writer refuses a retired one and a choice that
            // fails on save is worse than a choice that is not offered.
            //
            // An empty list is the honest answer on a fresh installation and
            // the screen says what to do about it, rather than offering a form
            // whose only required field has nothing in it.
            'profiles' => $profiles->selectable(),
        ]);
    }
}
