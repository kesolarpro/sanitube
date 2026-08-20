<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Services;

use SaniTube\AI\AiManager;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Transcription\Models\Transcript;

/**
 * Whether this asset is worth asking a model about.
 *
 * The same shape as transcription's, and asked in the same two places for the
 * same reason: once when the work is requested so a refusal reaches the person
 * who asked, and again inside the job, because a queue is a delay and the last
 * point at which noticing something is free rather than billed.
 *
 * It answers about the asset. Whether the installation is paused and whether
 * the queue has room are separate questions with separate gates.
 */
final readonly class EnrichmentEligibility
{
    public function __construct(private AiManager $models) {}

    /**
     * Whether this installation has a model at all.
     *
     * Asked separately from `refusalFor()` because "no model is configured" is
     * a property of the installation and every other refusal is a property of
     * one asset. A backlog sweep needs to say the first *once*, before it walks
     * a catalogue to be told the same thing four thousand times.
     */
    public function hasModel(): bool
    {
        return $this->models->isAvailable();
    }

    public function refusalFor(Asset $asset): ?EnrichmentRefusal
    {
        if (! $this->models->isAvailable()) {
            return EnrichmentRefusal::ModelUnavailable;
        }

        if ($asset->isTrashed()) {
            return EnrichmentRefusal::AssetTrashed;
        }

        if (! $this->evidenceFor($asset) instanceof Transcript) {
            // Nothing to reason from. Asking a model to describe a recording it
            // has been told nothing about produces a confident paragraph of
            // invention, which is the worst possible thing to put in a review
            // queue: it looks exactly like an observation.
            return EnrichmentRefusal::NoEvidence;
        }

        $waiting = MetadataSuggestion::query()
            ->where('asset_id', $asset->id)
            ->where('task', MetadataSuggestion::TASK_TRACK_METADATA)
            ->where('status', SuggestionStatus::Proposed->value)
            ->exists();

        // A second suggestion nobody asked for, sitting behind one nobody has
        // read, is a paid call whose only effect is to supersede the answer
        // still waiting for a person.
        return $waiting ? EnrichmentRefusal::AlreadyWaiting : null;
    }

    public function allows(Asset $asset): bool
    {
        return $this->refusalFor($asset) === null;
    }

    /**
     * The most recent transcript for this asset.
     *
     * Most recent rather than any: a transcript re-made by a newer provider
     * version is better evidence, and reasoning from the older one would
     * produce a suggestion whose `evidence_version` says it is already stale.
     */
    public function evidenceFor(Asset $asset): ?Transcript
    {
        return Transcript::query()
            ->where('asset_id', $asset->id)
            ->orderByDesc('transcribed_at')
            ->orderByDesc('id')
            ->first();
    }
}
