<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\ExternalIdentifierRevocation;

/**
 * An identifier was withdrawn. The row survives — downstream systems still
 * hold it and the catalogue has to be able to explain it.
 */
final class ExternalIdentifierRevoked
{
    use Dispatchable;

    public function __construct(
        public readonly ExternalIdentifier $identifier,
        public readonly ExternalIdentifierRevocation $revocation,
    ) {}
}
