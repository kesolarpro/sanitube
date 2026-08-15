<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;

final class CompositionShareException extends DomainException
{
    public static function exceedsHundred(string $role, string $total): self
    {
        return new self(sprintf(
            'Credited shares for role [%s] would total %s%%, which exceeds 100%%.',
            $role,
            $total,
        ));
    }
}
