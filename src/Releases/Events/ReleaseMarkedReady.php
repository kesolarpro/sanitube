<?php

declare(strict_types=1);

namespace SaniTube\Releases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Releases\Models\Release;

/**
 * A release passed its readiness checks and may be submitted.
 */
final class ReleaseMarkedReady
{
    use Dispatchable;

    public function __construct(public readonly Release $release) {}
}
