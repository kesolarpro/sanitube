<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Transcription\Events\AssetTranscribed;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Services\TranscribeAsset;
use SaniTube\Transcription\Services\TranscriptionEligibility;

/**
 * Transcribes one asset, off the request.
 *
 * A transcription is a long call to somebody else's server over a file that had
 * to be spooled down first. Doing that inside a web request would hold a PHP
 * worker on a shared account for minutes, which is the failure OPS-002 exists
 * to prevent.
 *
 * Carries a uuid rather than the model, like every other job here: a serialised
 * model is a snapshot taken when the job was queued, and this job's whole
 * purpose is to act on the row **as it is now**.
 *
 * **Eligibility is checked again here, and that is not belt-and-braces.** A
 * queue is a delay. Between the request and the work, the asset can be
 * trashed, confirmed as a duplicate, or transcribed by another route — and this
 * is the last point at which noticing that is free rather than billed.
 *
 * **Safely retryable.** The transcription service is idempotent by provider and
 * version, so a second run returns the stored row instead of paying again, and
 * this job's own eligibility check refuses before it gets that far.
 */
final class TranscribeAssetJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $assetUuid,
        public readonly ?string $languageHint = null,
    ) {}

    public function handle(TranscribeAsset $transcriber, TranscriptionEligibility $eligibility): void
    {
        $asset = Asset::query()->where('uuid', $this->assetUuid)->first();

        if (! $asset instanceof Asset) {
            return;
        }

        if (! $eligibility->allows($asset)) {
            // Not a failure. The queue is a delay, and something legitimate
            // changed inside it; treating that as an error would fill the
            // failed-jobs screen with assets somebody deliberately trashed.
            return;
        }

        $transcript = $transcriber->handle($asset, $this->languageHint);

        // Null means the supplier could not, or heard nothing worth storing.
        // Both are ordinary. Announcing a transcript that does not exist would
        // send enrichment looking for evidence nobody wrote.
        if ($transcript instanceof Transcript) {
            AssetTranscribed::dispatch($transcript);
        }
    }
}
