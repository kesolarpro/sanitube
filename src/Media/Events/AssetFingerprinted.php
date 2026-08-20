<?php

declare(strict_types=1);

namespace SaniTube\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Media\Models\AudioFingerprint;

/**
 * An asset now has an acoustic fingerprint.
 *
 * An event rather than a direct call, for the reason every other seam here is
 * one: Media has measured something and has no opinion about what it is for.
 * Deduplication decides that a new fingerprint is worth comparing, and a module
 * that has not been installed simply does not listen — which is what keeps the
 * dependency pointing one way.
 */
final class AssetFingerprinted
{
    use Dispatchable;

    public function __construct(public readonly AudioFingerprint $fingerprint) {}
}
