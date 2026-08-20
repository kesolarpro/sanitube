<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\SafelyRetryable;

/**
 * Measures one artwork asset.
 *
 * Carries the uuid rather than the model, like every other job here: a
 * serialised model is a snapshot taken when the job was queued, and a worker
 * picking it up later needs the row as it is now — this one in particular,
 * because an asset can be quarantined between queueing and running.
 *
 * **Safely retryable.** It writes a measurement of bytes that do not change,
 * keyed by asset and measurer version. A second run measures the same file and
 * overwrites its own previous answer; nothing downstream is committed by it.
 */
final class MeasureArtworkJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $assetUuid) {}

    public function handle(MeasureArtwork $measurer): void
    {
        $asset = Asset::query()->where('uuid', $this->assetUuid)->first();

        if ($asset instanceof Asset) {
            $measurer->handle($asset);
        }
    }
}
