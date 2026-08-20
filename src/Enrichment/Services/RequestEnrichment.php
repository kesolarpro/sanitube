<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Services;

use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;

/**
 * Asking for one asset to be described.
 *
 * The single entry point, exactly as transcription has one: the button, the
 * listener and any future sweep all come through here, so the three gates are
 * asked in the same order every time and no caller can acquire the work without
 * acquiring the guards.
 *
 * The installation's switch first, because an operator who paused the platform
 * meant it. The queue's capacity second, because that is a measurement. The
 * asset last, because it is the only one whose answer is about this file.
 *
 * **Nothing is asked of a model inside this call.** A completion does not
 * belong in a web request, and a person who pressed a button should get an
 * answer rather than a timeout.
 */
final readonly class RequestEnrichment
{
    public function __construct(
        private WorkAdmission $admission,
        private EnrichmentEligibility $eligibility,
    ) {}

    /**
     * @return EnrichmentRefusal|null null when the work was queued
     *
     * @throws WorkRefused when the installation is paused or the queue is full
     */
    public function for(Asset $asset): ?EnrichmentRefusal
    {
        $this->admission->admit(1);

        $refusal = $this->eligibility->refusalFor($asset);

        if ($refusal instanceof EnrichmentRefusal) {
            return $refusal;
        }

        SuggestMetadataJob::dispatch($asset->uuid);

        return null;
    }
}
