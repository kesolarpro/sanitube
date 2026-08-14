<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\Composition;

/**
 * A new underlying work exists.
 */
final class CompositionCreated
{
    use Dispatchable;

    public function __construct(public readonly Composition $composition) {}
}
