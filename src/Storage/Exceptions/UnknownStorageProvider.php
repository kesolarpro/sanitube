<?php

declare(strict_types=1);

namespace SaniTube\Storage\Exceptions;

use InvalidArgumentException;

final class UnknownStorageProvider extends InvalidArgumentException
{
    /**
     * @param  list<string>  $configured
     */
    public static function for(string $name, array $configured): self
    {
        return new self(sprintf(
            'Storage provider [%s] is not configured. Configured providers: %s.',
            $name,
            $configured === [] ? '(none)' : implode(', ', $configured),
        ));
    }
}
