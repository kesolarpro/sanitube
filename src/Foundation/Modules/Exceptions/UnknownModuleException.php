<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Modules\Exceptions;

use InvalidArgumentException;

final class UnknownModuleException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function for(string $name, array $known): self
    {
        return new self(sprintf(
            'Unknown SaniTube module [%s]. Registered modules: %s.',
            $name,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
