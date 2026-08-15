<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ui\Queries\TrackCandidateDetailQuery;

/**
 * One proposal, with everything a reviewer needs to decide. Bound by UUID.
 */
final class CandidateDetailController
{
    public function __invoke(
        Request $request,
        TrackCandidate $candidate,
        TrackCandidateDetailQuery $detail,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Ingestion/Candidates/Show', [
            'candidate' => $detail->forCandidate($candidate, $viewer),
        ]);
    }
}
