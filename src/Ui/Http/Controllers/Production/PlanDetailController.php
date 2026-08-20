<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Production;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Ui\Queries\ProductionPlanDetailQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One plan, and every occasion it opened.
 *
 * The screen that answers "why did nothing happen on the 14th". Bound by uuid,
 * like every other detail screen: an internal id in a URL is a number somebody
 * increments.
 */
final class PlanDetailController
{
    public function __invoke(Request $request, ProductionPlan $plan, ProductionPlanDetailQuery $plans): Response
    {
        /** @var User|null $viewer */
        $viewer = $request->user();

        $data = $plans->get($plan->uuid, $viewer);

        if ($data === null) {
            // Deleted between the route binding and the read. Rare, and a 404
            // is the honest answer rather than an empty screen.
            throw new NotFoundHttpException;
        }

        return Inertia::render('Production/Show', $data);
    }
}
