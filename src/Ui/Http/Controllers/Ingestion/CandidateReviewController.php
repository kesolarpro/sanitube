<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\RejectTrackCandidate;
use SaniTube\Ingestion\Services\ReviseTrackCandidate;
use SaniTube\Ui\Http\Requests\Ingestion\PromoteCandidateRequest;
use SaniTube\Ui\Http\Requests\Ingestion\RejectCandidateRequest;
use SaniTube\Ui\Http\Requests\Ingestion\ReviseCandidateRequest;

/**
 * The three decisions a reviewer can take about a proposal.
 *
 * Every one of them calls the CAT-001 service that owns it. **Nothing here
 * writes a status.** `$candidate->update(['status' => ...])` would look like
 * the same thing and would skip the guarded UPDATE that makes double promotion
 * impossible under a race, the duplicate-master check, the asset verification
 * check, and the requirement that overruling the machine be justified in
 * writing. A controller that assigns a status is a second, weaker copy of a
 * domain service.
 *
 * Refusals come back as `withErrors`, not as an exception page. Every
 * `CandidateReviewException` carries a machine-readable reason, and every one
 * of them is something the reviewer can act on — supply a note, wait for
 * analysis, promote a different candidate. The reason code travels; the
 * interface translates it. The domain's English sentence is never rendered,
 * for the same reason a storage driver's message is not: it is written for a
 * developer reading a log.
 *
 * Promotion and rejection are audited; revision is not. The first two are
 * decisions with a subject that stops being reviewable — a Track now exists,
 * or a proposal is closed — and `reviewed_by` on the candidate says who but
 * not when they were refused. Revision edits a draft nobody has acted on yet,
 * and every edit of every field would be a changelog rather than an audit log.
 */
final class CandidateReviewController
{
    /**
     * Promote — the one action in the interface that creates a Track.
     */
    public function promote(
        PromoteCandidateRequest $request,
        TrackCandidate $candidate,
        PromoteTrackCandidate $promoter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $track = $promoter->handle(
                candidate: $candidate,
                attributes: $request->catalogueAttributes(),
                override: $request->override(),
                note: $request->note(),
                // Recorded, unlike the API's call, which leaves it null. A
                // decision that changed the catalogue and names nobody is a
                // decision nobody can be asked about.
                reviewerId: $reviewer->getKey(),
            );
        } catch (CandidateReviewException $exception) {
            $audit->refused(AuditAction::CandidatePromoted, $exception->reason, $candidate->uuid);

            return $this->refused($exception);
        }

        // `override` is recorded because it is the one place a person can
        // overrule the machine, and "who promoted a candidate the analysis had
        // flagged" is a question somebody eventually asks. The note is not
        // copied: it is already on the candidate, and free text belongs where
        // it can be edited rather than in a table that refuses to be.
        $audit->record(
            AuditAction::CandidatePromoted,
            subjectUuid: $candidate->uuid,
            context: ['track' => $track->uuid, 'override' => $request->override()],
        );

        // To the Track, not back to the candidate. The reviewer asked for a
        // recording to exist in the catalogue; showing them the thing they
        // made is the answer, and it is also the only way they can see that it
        // arrived as a DRAFT rather than as something publishable.
        return redirect()
            ->to('/catalog/tracks/'.$track->uuid)
            ->with('status', 'candidate.promoted');
    }

    public function reject(
        RejectCandidateRequest $request,
        TrackCandidate $candidate,
        RejectTrackCandidate $rejecter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $rejecter->handle($candidate, $request->reason(), $reviewer->getKey());
        } catch (CandidateReviewException $exception) {
            $audit->refused(AuditAction::CandidateRejected, $exception->reason, $candidate->uuid);

            return $this->refused($exception);
        }

        $audit->record(AuditAction::CandidateRejected, subjectUuid: $candidate->uuid);

        return back()->with('status', 'candidate.rejected');
    }

    public function revise(
        ReviseCandidateRequest $request,
        TrackCandidate $candidate,
        ReviseTrackCandidate $reviser,
    ): RedirectResponse {
        try {
            $reviser->handle($candidate, $request->revisions());
        } catch (CandidateReviewException $exception) {
            return $this->refused($exception);
        }

        return back()->with('status', 'candidate.revised');
    }

    /**
     * A refusal the reviewer can act on, carrying only its reason code.
     */
    private function refused(CandidateReviewException $exception): RedirectResponse
    {
        return back()->withErrors(['review' => $exception->reason]);
    }
}
