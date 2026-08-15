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

    /**
     * A person looked and said no. Distinct from FAILED, which is the platform
     * saying it could not. A rejected candidate is kept rather than deleted:
     * re-importing the same folder must not silently propose it again as
     * though nobody had ever considered it.
     */
    case Rejected = 'REJECTED';

    case Promoted = 'PROMOTED';

    /**
     * Only a READY candidate may be promoted. Everything else is either not
     * finished, already promoted, or something a human has to look at.
     */
    public function isPromotable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * States a reviewer may promote *out of*, having said so explicitly.
     *
     * Separate from {@see isPromotable()} on purpose. NEEDS_REVIEW and
     * DUPLICATE are not ready — that is the whole point of them — but a person
     * who has looked at the file may legitimately overrule the machine, and a
     * platform with no way to do that ends up with a spreadsheet beside it.
     * The override is recorded with a note; it is never a default.
     */
    public function isPromotableWithOverride(): bool
    {
        return $this === self::NeedsReview || $this === self::Duplicate;
    }

    /**
     * Whether a reviewer may still change this candidate's suggested metadata.
     */
    public function isRevisable(): bool
    {
        return $this !== self::Promoted && $this !== self::Rejected;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::WaitingCapability => false,
            default => true,
        };
    }
}
