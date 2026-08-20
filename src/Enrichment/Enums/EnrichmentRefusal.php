<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Enums;

/**
 * Why this asset is not going to be described.
 *
 * A closed list, for the reason every refusal list here is closed: "we did not
 * enrich it" is a thing an operator will eventually need counted, and a reason
 * somebody typed is a reason nobody can count.
 *
 * Every one is an ordinary state. Most installations configure no model at all,
 * and a library where most assets have no transcript to reason from is a
 * library working exactly as designed.
 */
enum EnrichmentRefusal: string
{
    /** No model is configured, or the configured one has no usable key. */
    case ModelUnavailable = 'ENRICHMENT_MODEL_UNAVAILABLE';

    /** Somebody set this asset aside. */
    case AssetTrashed = 'ENRICHMENT_ASSET_TRASHED';

    /**
     * There is no transcript to reason from.
     *
     * **Not a gap to be worked around.** Asking a model to describe a recording
     * it has been told nothing about produces a confident paragraph of pure
     * invention — and in a review queue that is worse than an empty row,
     * because it looks exactly like an observation.
     */
    case NoEvidence = 'ENRICHMENT_NO_EVIDENCE';

    /**
     * A suggestion is already waiting for a person.
     *
     * A second one would be a paid call whose only effect is to supersede the
     * answer nobody has read yet.
     */
    case AlreadyWaiting = 'ENRICHMENT_ALREADY_WAITING';
}
