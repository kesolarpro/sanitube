<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Listeners;

use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;
use SaniTube\Media\Events\AssetFingerprinted;

/**
 * Compares an asset the moment there is something new to compare it with.
 *
 * Deduplication listens to Media rather than Media calling deduplication, so
 * the dependency points one way and an installation without this module simply
 * has nobody listening.
 */
final readonly class EvaluateWhenFingerprinted
{
    public function __construct(private EvaluateAssetDuplicates $evaluate) {}

    public function handle(AssetFingerprinted $event): void
    {
        $asset = $event->fingerprint->asset;

        if ($asset !== null) {
            $this->evaluate->for($asset);
        }
    }
}
