<?php

declare(strict_types=1);

namespace SaniTube\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Assets\Models\Asset;

/**
 * Verification looked for the object and it was not there.
 *
 * The loudest thing the platform can say. An asset reaching this state means
 * the catalogue is describing bytes that no longer exist — a lifecycle rule, a
 * provider swap, a restore that overwrote less than it replaced. It is emitted
 * as an event rather than merely logged so an installation can wire it to
 * whatever it actually reads.
 */
final class AssetMissing
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
