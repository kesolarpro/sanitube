<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Listeners;

use SaniTube\Assets\Events\AssetStored;
use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;

/**
 * Compares an asset as soon as its bytes are known.
 *
 * **Both this and {@see EvaluateWhenFingerprinted} exist, and the overlap is
 * deliberate.** Most installations have no Chromaprint, so if evaluation only
 * ever followed a fingerprint they would never find a duplicate at all — not
 * even the byte-identical ones, which need no acoustic comparison and are the
 * commonest case by far. Running on `AssetStored` means the checksum half works
 * everywhere; running again on a fingerprint adds the acoustic half where it is
 * available.
 *
 * Evaluating twice is free: `EvaluateAssetDuplicates` is idempotent per pair
 * and route, and DEDUP-001 asserts that re-running does not grow the queue.
 */
final readonly class EvaluateWhenStored
{
    public function __construct(private EvaluateAssetDuplicates $evaluate) {}

    public function handle(AssetStored $event): void
    {
        $this->evaluate->for($event->asset);
    }
}
