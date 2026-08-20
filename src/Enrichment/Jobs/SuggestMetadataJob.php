<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Services\EnrichmentEligibility;
use SaniTube\Enrichment\Services\SuggestTrackMetadata;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Transcription\Models\Transcript;

/**
 * Asks a model about one asset, off the request.
 *
 * A completion is a call to somebody else's server that can take half a minute
 * and costs money. Doing it inside a web request holds a PHP worker on a shared
 * account for the duration, which is the failure OPS-002 exists to prevent.
 *
 * Carries a uuid rather than the model, like every job here: this one's whole
 * purpose is to act on the row **as it is now**, and a serialised model is a
 * snapshot from when the job was queued.
 *
 * **Eligibility is re-checked, and that is what protects the bill.** In the
 * delay between queueing and running, the asset can be trashed, a suggestion
 * can arrive by another route, or the model can be unconfigured — and this is
 * the last point at which noticing costs nothing.
 *
 * **Safely retryable.** A second run finds the suggestion the first one left
 * waiting and refuses before it reaches a vendor.
 */
final class SuggestMetadataJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. **A retried job is a second billed completion**, and deciding
     * whether to pay twice is a person's call rather than a queue worker's.
     *
     * Stated rather than left to the framework default, because the default is
     * a configuration value somebody can change without ever seeing this
     * class, and the cost of that change is an invoice.
     */
    public int $tries = 1;

    public function __construct(public readonly string $assetUuid) {}

    public function handle(
        SuggestTrackMetadata $suggest,
        EnrichmentEligibility $eligibility,
    ): void {
        $asset = Asset::query()->where('uuid', $this->assetUuid)->first();

        if (! $asset instanceof Asset) {
            return;
        }

        if (! $eligibility->allows($asset)) {
            // Not a failure. Something legitimate changed inside the queue, and
            // treating that as an error would fill the failed-jobs screen with
            // assets somebody deliberately trashed.
            return;
        }

        $evidence = $eligibility->evidenceFor($asset);

        if (! $evidence instanceof Transcript) {
            return;
        }

        $suggest->handle($asset, $evidence);
    }
}
