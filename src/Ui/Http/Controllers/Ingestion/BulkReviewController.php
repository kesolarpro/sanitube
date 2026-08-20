<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Ingestion\Jobs\ReviewTrackCandidateJob;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;
use SaniTube\Ui\Http\Requests\Ingestion\BulkReviewRequest;

/**
 * Deciding about many candidates at once.
 *
 * BULK-002. Nine hundred imported files produce nine hundred candidates, and
 * before this the only way through them was nine hundred page loads. What this
 * adds is a way to *ask*; the decisions themselves are unchanged, and every one
 * of them still goes through the same service, the same invariants and the same
 * audit entry a single decision does.
 *
 * **Nothing is decided inside this request.** One job per candidate, queued.
 * A loop of a thousand transactions in an HTTP request is a request that times
 * out halfway with no record of where it stopped — and the half that succeeded
 * would have changed the catalogue.
 *
 * **Capacity is asked before anything is queued.** OPS-002's admission is the
 * same check `StartIngestionBatch` makes for the same reason: refusing now,
 * while somebody is looking at the response, beats stranding two hundred jobs
 * in a queue nobody is watching because background work is paused.
 *
 * **The uuids are resolved, never trusted.** A candidate that does not exist is
 * dropped here rather than queued to be dropped by a worker; the response says
 * how many were actually taken, so a selection that was half stale does not
 * read as a selection that was fully accepted.
 */
final class BulkReviewController
{
    public function __invoke(
        BulkReviewRequest $request,
        WorkAdmission $admission,
    ): RedirectResponse {
        /** @var User $reviewer */
        $reviewer = $request->user();

        // Resolved to what actually exists, and to uuids rather than to models:
        // the job re-reads the row, because a snapshot taken now would be a lie
        // by the time a worker reaches it.
        //
        // This is also where a repeated uuid stops being two: each row comes
        // back once, so a selection submitted three times over queues one job.
        // The request deliberately does not deduplicate as well — a second
        // guarantee in a second place is one that eventually disagrees.
        /** @var list<string> $uuids */
        $uuids = TrackCandidate::query()
            ->whereIn('uuid', $request->candidates())
            ->pluck('uuid')
            ->all();

        if ($uuids === []) {
            return back()->withErrors(['review' => 'NOTHING_SELECTED']);
        }

        try {
            $admission->admit(count($uuids));
        } catch (WorkRefused $exception) {
            return back()->withErrors(['review' => $exception->reason]);
        }

        foreach ($uuids as $uuid) {
            ReviewTrackCandidateJob::dispatch(
                candidateUuid: $uuid,
                promote: $request->promoting(),
                reviewerId: $reviewer->getKey(),
                reason: $request->reason(),
            );
        }

        return back()->with('status', 'candidate.bulk_queued');
    }
}
