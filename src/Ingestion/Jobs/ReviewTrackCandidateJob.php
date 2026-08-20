<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\RejectTrackCandidate;

/**
 * One decision, one job.
 *
 * BULK-002. Nine hundred imported files produce nine hundred candidates, and
 * before this the only way through them was nine hundred page loads. The
 * decisions themselves are unchanged — this is the same `PromoteTrackCandidate`
 * and the same `RejectTrackCandidate` a person invokes one at a time, with the
 * same invariants, the same refusals and the same audit entries.
 *
 * **Queued rather than looped inside a request.** Promotion is a transaction
 * with no external call, so a hundred of them in an HTTP request would merely
 * be slow; a thousand would be a request that times out halfway with no record
 * of where it stopped. One job per candidate is interruptible, retryable, and
 * leaves every undecided candidate exactly where it was.
 *
 * **Carries the uuid, not the model.** A serialised model is a snapshot taken
 * when the job was queued; by the time a worker reaches it somebody may have
 * decided that candidate by hand. Re-reading the row is what makes the domain's
 * own idempotency check mean anything — `alreadyPromoted` and `alreadyDecided`
 * are refusals it already knows how to raise.
 *
 * **A refusal is not a failure of the job.** A candidate the domain declines to
 * promote — one still marked NEEDS_REVIEW, one whose asset never verified — is
 * an ordinary outcome of asking about nine hundred things at once. It is
 * recorded and the candidate is left where it was, in the state that explains
 * why. Throwing would retry the job three times to be told the same thing, and
 * would then bury a routine answer in the failed-jobs table.
 */
final class ReviewTrackCandidateJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $candidateUuid,
        public readonly bool $promote,
        public readonly ?int $reviewerId = null,
        public readonly string $reason = '',
    ) {}

    public function tries(): int
    {
        return 3;
    }

    public function handle(
        PromoteTrackCandidate $promoter,
        RejectTrackCandidate $rejecter,
        RecordAuditEvent $audit,
    ): void {
        $candidate = TrackCandidate::query()->where('uuid', $this->candidateUuid)->first();

        if (! $candidate instanceof TrackCandidate) {
            // Trashed, or decided and cleaned up, between queueing and running.
            // Nothing to do and nothing wrong.
            return;
        }

        $action = $this->promote ? AuditAction::CandidatePromoted : AuditAction::CandidateRejected;

        try {
            if ($this->promote) {
                // The suggested title, because that is the only title a bulk
                // decision has. A candidate with none is refused by name
                // (TITLE_REQUIRED) rather than promoted as "untitled" — a
                // catalogue row nobody chose the name of is worse than one
                // that did not appear.
                $track = $promoter->handle(
                    candidate: $candidate,
                    attributes: [],
                    // Never overridden in bulk, and that is the point.
                    // Overriding is where a person overrules the machine about
                    // one recording they have looked at; doing it nine hundred
                    // at a time is exactly what the override exists to prevent.
                    override: false,
                    note: '',
                    reviewerId: $this->reviewerId,
                );

                $audit->record($action, subjectUuid: $candidate->uuid, context: [
                    'track' => $track->uuid,
                    'bulk' => 'true',
                ]);

                return;
            }

            $rejecter->handle($candidate, $this->reason, $this->reviewerId);

            $audit->record($action, subjectUuid: $candidate->uuid, context: ['bulk' => 'true']);
        } catch (CandidateReviewException $exception) {
            $audit->refused($action, $exception->reason, $candidate->uuid, context: ['bulk' => 'true']);
        }
    }
}
