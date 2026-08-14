<?php

declare(strict_types=1);

namespace SaniTube\Storage\Exceptions;

use RuntimeException;
use Throwable;

final class StorageOperationFailed extends RuntimeException
{
    public static function write(string $provider, string $key): self
    {
        return new self(sprintf('Failed to write [%s] to storage provider [%s].', $key, $provider));
    }

    public static function read(string $provider, string $key): self
    {
        return new self(sprintf('Failed to read [%s] from storage provider [%s].', $key, $provider));
    }

    public static function unreadableSource(string $path): self
    {
        return new self(sprintf('Source file [%s] could not be opened for reading.', $path));
    }

    public static function noPublicUrl(string $provider, string $key, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Storage provider [%s] cannot produce a public URL for [%s].', $provider, $key),
            previous: $previous,
        );
    }
}
