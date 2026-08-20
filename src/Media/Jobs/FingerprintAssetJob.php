<?php

declare(strict_types=1);

namespace SaniTube\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Media\Events\AssetFingerprinted;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Services\FingerprintAsset;

/**
 * Takes one asset's acoustic fingerprint, and says so.
 *
 * A job separate from analysis rather than a step inside it, because the two
 * need different things to be installed. FFmpeg and Chromaprint arrive
 * independently, and a server with one and not the other should get the half it
 * can do — folding fingerprinting into `AnalyzeAudioAssetJob` would make a
 * missing `fpcalc` look like a failed analysis.
 *
 * Carries a uuid rather than the model, like every other job here: a serialised
 * model is a snapshot taken when the job was queued, and a worker picking it up
 * minutes later needs the row as it is now.
 *
 * **Safely retryable.** A fingerprint is a measurement of bytes that do not
 * change, keyed by asset and algorithm version. A second run measures the same
 * file and returns the stored answer.
 */
final class FingerprintAssetJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $assetUuid) {}

    public function handle(FingerprintAsset $fingerprinter): void
    {
        $asset = Asset::query()->where('uuid', $this->assetUuid)->first();

        if (! $asset instanceof Asset) {
            return;
        }

        $fingerprint = $fingerprinter->handle($asset);

        // Null means this installation cannot fingerprint, or this file could
        // not be. Both are ordinary states rather than failures, and neither is
        // worth announcing -- there is nothing new to compare.
        if ($fingerprint instanceof AudioFingerprint) {
            AssetFingerprinted::dispatch($fingerprint);
        }
    }
}
