<?php

declare(strict_types=1);

namespace SaniTube\Media\Listeners;

use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Media\Jobs\AnalyzeAudioAssetJob;

/**
 * Queues analysis when a candidate appears.
 *
 * A listener rather than a call inside ingestion, so that ingestion stays
 * ignorant of whether this server can analyse audio at all. An install without
 * FFmpeg runs exactly the same import; the job simply concludes that the
 * candidate is waiting on a capability.
 */
final readonly class ScheduleCandidateAnalysis
{
    public function handle(TrackCandidateCreated $event): void
    {
        AnalyzeAudioAssetJob::dispatch($event->candidate->uuid);
    }
}
