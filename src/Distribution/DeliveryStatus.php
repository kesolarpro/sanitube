<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

/**
 * SaniTube's own vocabulary for where a release stands with a distributor.
 *
 * Every adapter normalises its provider's states into these. Too Lost's
 * wording, TuneCore's wording and any future distributor's wording stop at the
 * adapter boundary; the catalogue only ever sees these values.
 */
enum DeliveryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Live = 'live';
    case TakedownRequested = 'takedown_requested';
    case TakenDown = 'taken_down';
    case Failed = 'failed';

    /**
     * Whether the distributor is still expected to act on its own.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Submitted, self::InReview, self::TakedownRequested => true,
            default => false,
        };
    }

    /**
     * Whether the release is currently on stores.
     */
    public function isPubliclyAvailable(): bool
    {
        return $this === self::Live;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::TakenDown, self::Failed => true,
            default => false,
        };
    }
}
