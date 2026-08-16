<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Ui\Queries\DeliveryDetailQuery;

/**
 * One delivery. Read-only, and it never asks the distributor anything —
 * refreshing a page must not be an outbound request. Asking is `sync`.
 */
final class DeliveryDetailController
{
    public function __invoke(
        Request $request,
        DistributionDelivery $delivery,
        DeliveryDetailQuery $detail,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Distribution/Show', [
            'delivery' => $detail->forDelivery($delivery, $viewer),
        ]);
    }
}
