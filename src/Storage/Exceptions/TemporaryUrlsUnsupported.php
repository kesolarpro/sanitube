<?php

declare(strict_types=1);

namespace SaniTube\Storage\Exceptions;

use RuntimeException;
use Throwable;

final class TemporaryUrlsUnsupported extends RuntimeException
{
    public static function for(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Storage provider [%s] does not support temporary URLs. '
                .'Serve the object through the application instead, or configure S3-compatible storage.',
                $provider,
            ),
            previous: $previous,
        );
    }
}
