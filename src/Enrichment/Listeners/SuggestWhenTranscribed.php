<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Listeners;

use SaniTube\Enrichment\Services\RequestEnrichment;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Transcription\Events\AssetTranscribed;

/**
 * Describes a recording as soon as there is something to describe — **only if
 * this installation asked for that.**
 *
 * Off by default, for the reason transcription's listener is off by default and
 * with one difference that makes it more important here: this is the *second*
 * paid call on the same asset. An installation that turned on automatic
 * transcription and automatic enrichment together has doubled a bill it has not
 * yet seen a single result from.
 *
 * `AssetTranscribed` is the right trigger rather than `AssetStored`, because
 * the transcript is the evidence. Firing on storage would ask a model to
 * describe a recording it has been told nothing about.
 *
 * A refusal is silent: the gates have already recorded why, and there is nobody
 * on the other end of a queued job to tell.
 */
final readonly class SuggestWhenTranscribed
{
    public function __construct(private RequestEnrichment $request) {}

    public function handle(AssetTranscribed $event): void
    {
        if (! (bool) config('enrichment.automatic')) {
            return;
        }

        try {
            $this->request->for($event->transcript->asset);
        } catch (WorkRefused) {
            // Paused, or the queue is full. Both are the platform working, and
            // neither is a reason to fail a transcription that succeeded.
        }
    }
}
