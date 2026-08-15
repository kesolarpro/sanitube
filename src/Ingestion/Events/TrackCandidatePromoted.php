<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Events;

use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * A person decided a proposal belongs in the catalogue.
 *
 * The one moment in the platform where imported material becomes a recording,
 * so it is announced rather than kept private: modules that know something the
 * catalogue does not — the measured duration, in MED-001's case — enrich the
 * track by listening, instead of Ingestion reaching into them and closing a
 * dependency cycle.
 *
 * Emitted inside the promoting transaction, so a listener that fails takes the
 * promotion with it rather than leaving a half-catalogued track behind.
 */
final class TrackCandidatePromoted
{
    public function __construct(
        public readonly TrackCandidate $candidate,
        public readonly Track $track,
    ) {}
}
