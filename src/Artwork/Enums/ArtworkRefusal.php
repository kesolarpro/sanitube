<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Enums;

/**
 * Why artwork was not generated.
 *
 * Machine-readable, per REL-002's rule: English is for logs, and a caller that
 * has to match on a sentence works around the check rather than fixing it.
 */
enum ArtworkRefusal: string
{
    /** No image provider is configured on this installation. */
    case NoProvider = 'NO_PROVIDER';

    /**
     * The provider cannot produce anything this installation would accept.
     *
     * The refusal this ticket exists for. Raised *before* a request is sent,
     * because paying for a cover the platform's own validator will reject is
     * worse than not generating one.
     */
    case RequirementsUnreachable = 'REQUIREMENTS_UNREACHABLE';

    /** The provider was asked and produced nothing usable. */
    case ProviderFailed = 'PROVIDER_FAILED';

    /** The bytes came back but could not be stored. */
    case NotStored = 'NOT_STORED';

    /** Nothing to describe the image with. */
    case EmptyPrompt = 'EMPTY_PROMPT';

    /*
     * ART-004. Three refusals that happen *before* anything is sent, and cost
     * nothing — which is the whole point of them existing.
     */

    /** A ceiling this installation set has been reached. Names which window. */
    case CeilingReached = 'CEILING_REACHED';

    /** The provider has failed repeatedly and is being left alone to recover. */
    case ProviderCircuitOpen = 'PROVIDER_CIRCUIT_OPEN';

    /** Artwork generation is switched off, or background work is paused. */
    case GenerationPaused = 'GENERATION_PAUSED';
}
