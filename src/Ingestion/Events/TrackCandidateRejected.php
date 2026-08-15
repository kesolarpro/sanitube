<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Events;

use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * A person looked at a proposal and said no.
 *
 * Distinct from a failure, and announced for the same reason a promotion is:
 * "this material was considered and declined" is a fact other parts of the
 * platform may need, and it is not recoverable from the absence of a Track.
 */
final class TrackCandidateRejected
{
    public function __construct(public readonly TrackCandidate $candidate) {}
}
