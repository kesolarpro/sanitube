<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

enum TrackStatus: string
{
    case Draft = 'DRAFT';
    case Ready = 'READY';
    case Released = 'RELEASED';
    case Archived = 'ARCHIVED';

    /**
     * Whether a track in this state may appear on a release being prepared.
     */
    public function isReleasable(): bool
    {
        return $this === self::Ready || $this === self::Released;
    }
}
