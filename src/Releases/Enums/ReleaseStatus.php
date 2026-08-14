<?php

declare(strict_types=1);

namespace SaniTube\Releases\Enums;

enum ReleaseStatus: string
{
    case Draft = 'DRAFT';
    case Ready = 'READY';
    case Submitted = 'SUBMITTED';
    case Delivered = 'DELIVERED';
    case Live = 'LIVE';
    case TakenDown = 'TAKEN_DOWN';

    /**
     * Once a release has been handed to a distributor, its tracks exist
     * outside SaniTube and can no longer be quietly removed from the
     * catalogue.
     */
    public function isCommitted(): bool
    {
        return match ($this) {
            self::Submitted, self::Delivered, self::Live => true,
            default => false,
        };
    }
}
