<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\ExternalIdentifier;

/**
 * The catalogue now knows an outside name for something it holds.
 */
final class ExternalIdentifierAssigned
{
    use Dispatchable;

    public function __construct(public readonly ExternalIdentifier $identifier) {}
}
