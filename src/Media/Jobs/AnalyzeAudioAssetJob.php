<?php

declare(strict_types=1);

namespace SaniTube\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;

/**
 * Analyses the asset behind one candidate, then decides what that makes the
 * candidate.
 *
 * Carries the candidate's uuid rather than the model, for the same reason
 * every other job here does: a serialised model is a snapshot of a row taken
 * when the job was queued, and a worker picking it up minutes later needs the
 * row as it is now.
 */
final class AnalyzeAudioAssetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $candidateUuid) {}

    public function handle(AnalyzeAsset $analyzer, SettleCandidateAfterAnalysis $settler): void
    {
        $candidate = TrackCandidate::query()->where('uuid', $this->candidateUuid)->first();

        if (! $candidate instanceof TrackCandidate) {
            return;
        }

        $asset = $candidate->asset;

        if (AnalyzeAsset::appliesTo($asset)) {
            $analyzer->handle($asset);
        }

        $settler->handle($candidate->refresh());
    }
}
