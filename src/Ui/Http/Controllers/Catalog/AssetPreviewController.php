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
 *
 * Neither response may be stored. A credential written into a shared proxy
 * cache, or into a browser's disk cache, outlives the tab that asked for it —
 * and `no-store` is the only directive that forbids writing it down at all.
 * `no-cache` alone would permit exactly that, on a promise to revalidate later.
 */
final class AssetPreviewController
{
    /**
     * Headers that forbid writing this response down anywhere.
     *
     * `Pragma` is there for HTTP/1.0 intermediaries — which some corporate
     * proxies still are, and which ignore `Cache-Control` entirely.
     */
    private function uncacheable(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function __invoke(Request $request, Asset $asset, MintAssetPreviewUrl $mint): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $result = $mint->for($asset, $viewer);

        if ($result['url'] === null) {
            // 403 rather than 404: the asset exists and the reader is allowed to
            // know that — they reached its detail page through the catalogue.
            //
            // The refusal is uncacheable too: whether a person may preview an
            // asset depends on their role and on the asset's status, and a
            // cached "no" would survive either of those changing.
            return $this->uncacheable(response()->json(['reason' => $result['decision']->value], 403));
        }

        return $this->uncacheable(response()->json([
            'url' => $result['url'],
            'expires_at' => $result['expires_at'],
        ]));
    }
}
