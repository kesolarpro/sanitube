<?php

declare(strict_types=1);

namespace SaniTube\Assets\Enums;

enum AssetKind: string
{
    case AudioMaster = 'AUDIO_MASTER';
    case AudioDerivative = 'AUDIO_DERIVATIVE';
    case Preview = 'PREVIEW';
    case Stem = 'STEM';
    case Artwork = 'ARTWORK';
    case Video = 'VIDEO';
    case Lyrics = 'LYRICS';
    case Document = 'DOCUMENT';
    case LicenseDocument = 'LICENSE_DOCUMENT';
    case DistributionExport = 'DISTRIBUTION_EXPORT';

    /**
     * A master is the top of a lineage: it is what everything else is derived
     * from, so it can never itself have a parent.
     */
    public function forbidsParent(): bool
    {
        return $this === self::AudioMaster;
    }

    /**
     * Derived audio only means something in relation to what it was derived
     * from. Losing that link would leave a file nobody can trace back to a
     * master.
     */
    public function requiresParent(): bool
    {
        return $this === self::AudioDerivative || $this === self::Preview;
    }
}
