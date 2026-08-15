<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Assets\Models\Asset;
use SaniTube\Ui\Queries\AssetDetailQuery;

/**
 * One asset. Bound by UUID; carries no URL.
 */
final class AssetDetailController
{
    public function __invoke(Request $request, Asset $asset, AssetDetailQuery $detail): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Catalog/Assets/Show', [
            'asset' => $detail->forAsset($asset, $viewer),
        ]);
    }
}
