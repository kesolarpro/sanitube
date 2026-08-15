<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Enums;

/**
 * Whether a generated recording may be sold.
 *
 * `UNKNOWN` is the default, and that default is the entire point of this enum.
 * A generation existing says nothing about whether it can be distributed: that
 * depends on the provider's terms, the plan the account was on when the audio
 * was made, and in several cases on a human reading a licence. A platform that
 * assumed otherwise would put a track nobody is licensed to sell in front of a
 * distributor, and a takedown after the fact is not a fix.
 *
 * Nothing in SaniTube ever sets this automatically from the fact of a
 * successful generation.
 */
enum CommercialRightsStatus: string
{
    case Unknown = 'UNKNOWN';
    case Allowed = 'ALLOWED';
    case Restricted = 'RESTRICTED';
    case Prohibited = 'PROHIBITED';
    case ReviewRequired = 'REVIEW_REQUIRED';

    /**
     * Only an explicit ALLOWED clears a recording for distribution. Unknown is
     * not permission; it is the absence of an answer.
     */
    public function permitsDistribution(): bool
    {
        return $this === self::Allowed;
    }
}
