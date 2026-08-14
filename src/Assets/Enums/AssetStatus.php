<?php

declare(strict_types=1);

namespace SaniTube\Assets\Enums;

enum AssetStatus: string
{
    case Pending = 'PENDING';
    case Stored = 'STORED';
    case Verified = 'VERIFIED';
    case Quarantined = 'QUARANTINED';
    case Missing = 'MISSING';

    /**
     * Once bytes are on a disk, the record describing them is frozen. Editing
     * the path or the checksum of a stored asset is how a master silently
     * becomes a different file.
     */
    public function isImmutable(): bool
    {
        return $this === self::Stored || $this === self::Verified;
    }
}
