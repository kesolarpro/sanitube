<?php

declare(strict_types=1);

namespace SaniTube\Assets\Exceptions;

use RuntimeException;
use SaniTube\Assets\Enums\AssetStatus;

/**
 * Raised when bytes could not be taken in, or could not be trusted.
 *
 * Separate from {@see AssetIntegrityException}, which is about a record that
 * would become untrue. These are about an upload that did not happen: the
 * asset row survives, usually still PENDING, and the operation can be retried.
 */
final class AssetStorageException extends RuntimeException
{
    public static function tooLarge(string $kind, int $limitBytes): self
    {
        return new self(sprintf(
            'The upload exceeds the %s limit of %d bytes and was discarded. '
                .'Raise assets.max_upload_bytes if this is a legitimate file.',
            $kind,
            $limitBytes,
        ));
    }

    public static function empty(string $key): self
    {
        return new self(sprintf('The upload for [%s] contained no bytes.', $key));
    }

    public static function checksumMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'The stored object does not match the expected checksum. Expected %s, stored %s. '
                .'The upload was discarded rather than recorded.',
            $expected,
            $actual,
        ));
    }

    public static function sizeMismatch(int $expected, int $actual): self
    {
        return new self(sprintf(
            'The stored object is %d bytes, not the expected %d. '
                .'The upload was discarded rather than recorded.',
            $actual,
            $expected,
        ));
    }

    public static function notPending(AssetStatus $status): self
    {
        return new self(sprintf(
            'Only a PENDING asset can receive an upload; this one is %s. '
                .'Register a new asset rather than overwriting stored bytes.',
            $status->value,
        ));
    }

    public static function notStored(AssetStatus $status): self
    {
        return new self(sprintf(
            'An asset with status %s has nothing to verify. Verification confirms what is in '
                .'storage, so it applies only once bytes are there.',
            $status->value,
        ));
    }

    public static function contentMismatch(string $sha256): self
    {
        return new self(sprintf(
            'An upload for the same asset already exists with different content (%s). '
                .'Stored bytes are never replaced; register a new asset instead.',
            $sha256,
        ));
    }
}
