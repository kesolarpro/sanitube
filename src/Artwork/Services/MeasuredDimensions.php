<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Assets\Models\Asset;

/**
 * The dimensions of artwork, as measured.
 *
 * Exists because the screens were reading `assets.width` and `assets.height`,
 * and **nothing in this platform has ever written those columns.** They are
 * null on every production installation, and the only reason the suite did not
 * notice is that the asset factory sets 3000×3000 — so every test looked at a
 * perfectly-sized cover that had never existed.
 *
 * That is the failure ADR-0016 is about. A dimension nobody measured is not a
 * dimension, and presenting an empty column beside a verified checksum invites
 * a reader to believe the platform checked something it did not.
 *
 * So the read sites now ask here. An unmeasured cover reports null, which is
 * true, and the release validator says so separately with `COVER_NOT_MEASURED`.
 *
 * The dead columns are left in place. Dropping a column is destructive and
 * belongs to its own deliberate ticket; nothing reads them now.
 */
final readonly class MeasuredDimensions
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, array{width: int, height: int}> keyed by asset id
     */
    public function forAssetIds(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $dimensions = [];

        // Ordered ascending and allowed to overwrite, so the newest
        // measurement wins. A `MAX(id)` subquery per asset would say the same
        // thing in four dialects; this says it once, in PHP, and the row count
        // is bounded by the caller's own limit.
        $analyses = ArtworkAnalysis::query()
            ->whereIn('asset_id', $assetIds)
            ->orderBy('id')
            ->get();

        foreach ($analyses as $analysis) {
            // Asked of the row itself rather than re-derived here. What counts
            // as a usable measurement is one rule, and duplicating it produced
            // a condition no mutation could reach — two guards for one fact,
            // where breaking either left the other doing the work.
            $measurement = $analysis->measurement();

            if ($measurement === null) {
                continue;
            }

            $dimensions[$analysis->asset_id] = [
                'width' => $measurement->widthPx,
                'height' => $measurement->heightPx,
            ];
        }

        return $dimensions;
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    public function for(Asset $asset): array
    {
        $found = $this->forAssetIds([$asset->id])[$asset->id] ?? null;

        return $found ?? ['width' => null, 'height' => null];
    }
}
