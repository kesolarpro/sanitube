<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\OperationsQuery;

/**
 * Operational health, the scheduler, and the queue — all from stored state.
 *
 * A GET here probes nothing. This is the page somebody opens *during* an
 * outage, which is exactly when a page that does real I/O against five
 * external systems will not load.
 */
final class OperationsController
{
    public function __invoke(OperationsQuery $operations): Response
    {
        return Inertia::render('System/Operations', ['operations' => $operations->get()]);
    }
}
