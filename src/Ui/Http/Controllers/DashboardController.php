<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\DashboardQuery;

/**
 * The operational overview.
 *
 * Every figure on this screen is counted from the database or probed from a
 * provider at request time. There is no sample data, no placeholder metric and
 * no figure computed in the browser: the read model hands over a finished
 * snapshot, and the page renders it.
 */
final class DashboardController
{
    public function __invoke(DashboardQuery $dashboard): Response
    {
        return Inertia::render('Dashboard/Index', [
            'snapshot' => $dashboard->snapshot(),
        ]);
    }
}
