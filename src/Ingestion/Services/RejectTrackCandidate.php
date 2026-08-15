<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use Illuminate\Support\Carbon;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateRejected;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * Records that a person considered a proposal and declined it.
 *
 * Rejection keeps the row. Deleting it would mean the next import of the same
 * folder proposes the same file again as though nobody had ever looked — which
 * across nine hundred files is how a reviewer ends up doing the same work
 * twice and eventually stops trusting the queue.
 *
 * A reason is required. "Rejected" with no reason is a decision nobody can
 * revisit, and revisiting is exactly what happens when a distributor later
 * asks for the track that was thrown out.
 */
final readonly class RejectTrackCandidate
{
    public function handle(TrackCandidate $candidate, string $reason, ?int $reviewerId = null): TrackCandidate
    {
        if ($candidate->status === TrackCandidateStatus::Promoted) {
            // The track exists. Un-promoting is not a rejection; it is a
            // catalogue deletion, which is a different decision with different
            // consequences and belongs to whoever owns the catalogue.
            throw CandidateReviewException::alreadyPromoted($candidate->uuid);
        }

        if ($candidate->status === TrackCandidateStatus::Rejected) {
            throw CandidateReviewException::alreadyDecided($candidate->status);
        }

        if (trim($reason) === '') {
            throw CandidateReviewException::overrideNeedsANote($candidate->status);
        }

        $candidate->forceFill([
            'status' => TrackCandidateStatus::Rejected,
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $reviewerId,
            'review_note' => trim($reason),
        ])->save();

        event(new TrackCandidateRejected($candidate->refresh()));

        return $candidate;
    }
}
