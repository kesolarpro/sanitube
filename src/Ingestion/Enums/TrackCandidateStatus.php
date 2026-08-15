<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Enums;

/**
 * How far a candidate is from being worth promoting.
 *
 * A candidate is never a Track. It is a proposal that a Track could exist, and
 * only an explicit promotion turns it into one — which is why `PROMOTED` is a
 * terminal state here rather than the candidate disappearing.
 *
 * `WAITING_CAPABILITY` covers the case where the server simply cannot do the
 * work yet: FFmpeg is not installed, so the technical analysis a candidate
 * needs has not run. That is a fact about the server, not about the file, and
 * marking it FAILED would blame the recording for the host's configuration.
 */
enum TrackCandidateStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case WaitingCapability = 'WAITING_CAPABILITY';
    case Ready = 'READY';
    case Duplicate = 'DUPLICATE';
    case NeedsReview = 'NEEDS_REVIEW';
    case Failed = 'FAILED';
    case Promoted = 'PROMOTED';

    /**
     * Only a READY candidate may be promoted. Everything else is either not
     * finished, already promoted, or something a human has to look at.
     */
    public function isPromotable(): bool
    {
        return $this === self::Ready;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::WaitingCapability => false,
            default => true,
        };
    }
}
