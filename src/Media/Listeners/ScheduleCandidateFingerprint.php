<?php

declare(strict_types=1);

namespace SaniTube\Media\Listeners;

use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Media\Jobs\FingerprintAssetJob;

/**
 * Queues a fingerprint when a candidate appears.
 *
 * The same shape as {@see ScheduleCandidateAnalysis} and beside it rather than
 * inside it: FFmpeg and Chromaprint are installed independently, and a server
 * with one and not the other should get the half it can do.
 *
 * Ingestion stays ignorant of both. It announces that a candidate exists; Media
 * decides what this server is capable of doing about it.
 */
final readonly class ScheduleCandidateFingerprint
{
    public function handle(TrackCandidateCreated $event): void
    {
        FingerprintAssetJob::dispatch($event->candidate->asset->uuid);
    }
}
