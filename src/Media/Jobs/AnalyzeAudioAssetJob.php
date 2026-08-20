<?php

declare(strict_types=1);

namespace SaniTube\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\ApplyEmbeddedTags;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;

/**
 * Analyses the asset behind one candidate, then decides what that makes the
 * candidate.
 *
 * Carries the candidate's uuid rather than the model, for the same reason
 * every other job here does: a serialised model is a snapshot of a row taken
 * when the job was queued, and a worker picking it up minutes later needs the
 * row as it is now.

 * **Safely retryable.** Analysis writes a measurement of bytes that do not
 * change, keyed by asset. A second run measures the same file and overwrites
 * its own previous answer; nothing downstream is committed by it.
 */
final class AnalyzeAudioAssetJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $candidateUuid) {}

    public function handle(
        AnalyzeAsset $analyzer,
        SettleCandidateAfterAnalysis $settler,
        ApplyEmbeddedTags $tags,
    ): void {
        $candidate = TrackCandidate::query()->where('uuid', $this->candidateUuid)->first();

        if (! $candidate instanceof TrackCandidate) {
            return;
        }

        $asset = $candidate->asset;

        if (AnalyzeAsset::appliesTo($asset)) {
            $analyzer->handle($asset);
        }

        // TAG-001, before settling. The probe payload the analyser just stored
        // already carries what the file says about itself; reading it here
        // means a candidate arrives at review with its own title rather than
        // with a tidied filename.
        //
        // Before settling rather than after, because settling can mark the
        // candidate terminal — a candidate that goes straight to DUPLICATE
        // should still show what the file claimed, since that is part of what
        // a person decides from.
        $tags->handle($candidate->refresh(), $this->latestAnalysis($candidate));

        $settler->handle($candidate->refresh());
    }

    private function latestAnalysis(TrackCandidate $candidate): ?AudioAnalysis
    {
        return AudioAnalysis::query()
            ->where('asset_id', $candidate->asset_id)
            ->orderByDesc('id')
            ->first();
    }
}
