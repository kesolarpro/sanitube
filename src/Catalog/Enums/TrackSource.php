<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

/**
 * Where a recording came from. LEGACY_IMPORT is load-bearing: it marks tracks
 * that were already distributed elsewhere and therefore already carry an ISRC
 * the platform must preserve rather than mint.
 */
enum TrackSource: string
{
    case Upload = 'UPLOAD';
    case CloudImport = 'CLOUD_IMPORT';
    case Generated = 'GENERATED';
    case LegacyImport = 'LEGACY_IMPORT';
}
