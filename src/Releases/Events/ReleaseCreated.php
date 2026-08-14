<?php

declare(strict_types=1);

namespace SaniTube\Releases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Releases\Models\Release;

/**
 * A new release shell exists.
 */
final class ReleaseCreated
{
    use Dispatchable;

    public function __construct(public readonly Release $release) {}
}
