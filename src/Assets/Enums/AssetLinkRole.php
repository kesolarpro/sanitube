<?php

declare(strict_types=1);

namespace SaniTube\Assets\Enums;

/**
 * Secondary attachments only.
 *
 * MASTER and COVER are deliberately absent. A track's master is
 * `tracks.master_asset_id` and a release's cover is
 * `releases.cover_asset_id` — single-valued facts that belong in a foreign
 * key. Offering a second way to express them here would let the two disagree,
 * and then no reader could tell which one is true.
 */
enum AssetLinkRole: string
{
    case Preview = 'PREVIEW';
    case Stem = 'STEM';
    case Lyrics = 'LYRICS';
    case Contract = 'CONTRACT';
    case Export = 'EXPORT';
    case AlternateArtwork = 'ALTERNATE_ARTWORK';
}
