<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Enrichment\Models\MetadataSuggestion;
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

    /**
     * Ask again, over the top of an answer nobody has decided.
     *
     * **A distinct act rather than a second `for()`.** The ordinary path
     * refuses `ALREADY_WAITING`, and rightly so: a suggestion queued behind one
     * nobody has read is a paid call whose only effect is to supersede the
     * answer still waiting. But a reviewer looking at a poor suggestion and
     * wanting a better one is asking for exactly that, deliberately, and the
     * refusal is in their way rather than protecting them.
     *
     * It is not implemented as "reject, then request". A rejection is a person
     * saying the model was wrong, and it is counted -- a run of them against
     * one prompt version is how a bad prompt announces itself. Recording a
     * rejection somebody did not make would corrupt the only signal the
     * platform has about prompt quality.
     *
     * So the waiting suggestion is **superseded**, which is what actually
     * happened to it, and everything else goes through the same gates.
     *
     * @throws WorkRefused
     */
    public function again(Asset $asset): ?EnrichmentRefusal
    {
        $this->admission->admit(1);

        // The asset's own reasons still apply. A trashed asset is not
        // described because a reviewer pressed a button twice, and an
        // installation with no model still has no model.
        $refusal = $this->eligibility->refusalFor($asset);

        if ($refusal instanceof EnrichmentRefusal && $refusal !== EnrichmentRefusal::AlreadyWaiting) {
            return $refusal;
        }

        MetadataSuggestion::query()
            ->where('asset_id', $asset->id)
            ->where('task', MetadataSuggestion::TASK_TRACK_METADATA)
            ->where('status', SuggestionStatus::Proposed->value)
            ->update([
                'status' => SuggestionStatus::Superseded->value,
                'updated_at' => Carbon::now(),
            ]);

        SuggestMetadataJob::dispatch($asset->uuid);

        return null;
    }
}
