<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaniTube\Assets\Models\Asset;
use SaniTube\Ui\Assets\MintAssetPreviewUrl;

/**
 * Mints a preview URL — on request, never on a page load.
 *
 * A POST rather than a GET, and deliberately: this creates a credential. A GET
 * that mints one would be cached, prefetched by a browser, followed by a link
 * checker, and replayed from history — all of which would produce live
 * credentials nobody asked for.
 *
 * The response carries the URL and its expiry and nothing else. A refusal
 * carries a reason code the interface translates, never a sentence from the
 * storage layer.
 */
final class AssetPreviewController
{
    public function __invoke(Request $request, Asset $asset, MintAssetPreviewUrl $mint): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $result = $mint->for($asset, $viewer);

        if ($result['url'] === null) {
            // 403 rather than 404: the asset exists and the reader is allowed to
            // know that — they reached its detail page through the catalogue.
            return response()->json(['reason' => $result['decision']->value], 403);
        }

        return response()->json([
            'url' => $result['url'],
            'expires_at' => $result['expires_at'],
        ]);
    }
}
