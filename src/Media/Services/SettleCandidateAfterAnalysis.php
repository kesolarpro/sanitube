<?php

declare(strict_types=1);

namespace SaniTube\Media\Services;

use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Models\AudioAnalysis;

/**
 * Decides what a candidate is once analysis has had its turn.
 *
 * Three outcomes, and the distinction between them is the point of this ticket:
 *
 *   - **READY** — analysed, and the file is what it claimed.
 *   - **NEEDS_REVIEW** — the analyser ran and could not make sense of the
 *     file. That is a real finding about the material and a person should look.
 *   - **WAITING_CAPABILITY** — this server has no analyser *and* the operator
 *     has said analysis is required. Nothing is wrong with the recording; the
 *     host simply cannot answer yet, and the candidate becomes READY on its own
 *     once FFmpeg appears and the job is re-run.
 *
 * Marking that last case FAILED would blame a master for the server's
 * configuration, and would leave a permanent-looking failure behind long after
 * the missing binary was installed.
 *
 * **When analysis is not required — the default — an absent analyser produces
 * READY, not WAITING_CAPABILITY.** SaniTube must run on shared hosting that
 * cannot install FFmpeg, and a platform where no candidate is ever promotable
 * is a platform that does not work there. The analysis columns stay null, which
 * says exactly what was measured: nothing.
 *
 * A candidate that is already terminal is left alone. Promotion is the one
 * transition that matters and nothing here may undo it.
 */
final readonly class SettleCandidateAfterAnalysis
{
    public function __construct(private AnalyzeAsset $analyzer) {}

    public function handle(TrackCandidate $candidate): TrackCandidate
    {
        if ($candidate->status->isTerminal()) {
            return $candidate;
        }

        if (! $this->analyzer->isAvailable()) {
            return $this->mark($candidate, $this->required()
                ? TrackCandidateStatus::WaitingCapability
                : TrackCandidateStatus::Ready);
        }

        if (! AnalyzeAsset::appliesTo($candidate->asset)) {
            // Nothing to analyse and nothing to wait for.
            return $this->mark($candidate, TrackCandidateStatus::Ready);
        }

        $analysis = AudioAnalysis::query()
            ->where('asset_id', $candidate->asset_id)
            ->orderByDesc('id')
            ->first();

        if (! $analysis instanceof AudioAnalysis) {
            // The analyser is available but produced nothing — the asset was
            // not verified, or the job has not run. Neither is a verdict.
            return $candidate;
        }

        // An analyser that ran and failed is a finding about the file, and is
        // reported whether or not the operator required analysis. Suppressing
        // it under `analysis_required = false` would turn an optional
        // measurement into an optional *truth*.

        return $this->mark(
            $candidate,
            $analysis->succeeded ? TrackCandidateStatus::Ready : TrackCandidateStatus::NeedsReview,
        );
    }

    private function required(): bool
    {
        return (bool) config('media.analysis_required', false);
    }

    private function mark(TrackCandidate $candidate, TrackCandidateStatus $status): TrackCandidate
    {
        $candidate->forceFill(['status' => $status])->save();

        return $candidate->refresh();
    }
}
