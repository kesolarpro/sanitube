<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * Material entered the platform and is proposing to become a Track.
 *
 * The moment MED-001 will listen for to schedule technical analysis. Emitted
 * rather than calling analysis directly, so that ingestion does not have to
 * know whether the server can analyse anything.
 */
final class TrackCandidateCreated
{
    use Dispatchable;

    public function __construct(public readonly TrackCandidate $candidate) {}
}
