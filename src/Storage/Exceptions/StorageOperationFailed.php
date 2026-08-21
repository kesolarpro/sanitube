<?php

declare(strict_types=1);

namespace SaniTube\Storage\Exceptions;

use RuntimeException;

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

    public static function move(string $provider, string $from, string $to): self
    {
        return new self(sprintf(
            'Failed to move [%s] to [%s] on storage provider [%s].',
            $from,
            $to,
            $provider,
        ));
    }

    public static function unsafePrefix(string $prefix): self
    {
        return new self(sprintf(
            'Refusing to list [%s]: a listing prefix may not contain traversal or control characters.',
            $prefix,
        ));
    }

    public static function unreadableSource(string $path): self
    {
        return new self(sprintf('Source file [%s] could not be opened for reading.', $path));
    }
}
