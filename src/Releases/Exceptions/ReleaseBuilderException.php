<?php

declare(strict_types=1);

namespace SaniTube\Releases\Exceptions;

use RuntimeException;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\ReleaseMutationPolicy;

/**
 * A change to a release the platform will not make.
 *
 * Each carries a machine-readable `reason`, for the argument CAT-001 makes: a
 * 422 with no reason is what makes a caller guess.
 */
final class ReleaseBuilderException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEditable(ReleaseStatus $status): self
    {
        return new self(ReleaseMutationPolicy::refusalReason($status), 'RELEASE_NOT_EDITABLE');
    }

    public static function notReopenable(ReleaseStatus $status): self
    {
        return new self(
            sprintf(
                'A release in [%s] cannot be reopened. Only READY can be retracted; once a '
                    .'distributor has it, the recordings are outside SaniTube.',
                $status->value,
            ),
            'RELEASE_NOT_REOPENABLE',
        );
    }

    public static function trackAlreadyOnRelease(string $trackUuid): self
    {
        return new self(
            sprintf(
                'Track [%s] is already on this release. The same recording twice on one package is '
                    .'what a store reads as a duplicate delivery.',
                $trackUuid,
            ),
            'TRACK_ALREADY_ON_RELEASE',
        );
    }

    public static function trackNotOnRelease(string $trackUuid): self
    {
        return new self(sprintf('Track [%s] is not on this release.', $trackUuid), 'TRACK_NOT_ON_RELEASE');
    }

    public static function coverNotUsable(string $why): self
    {
        return new self($why, 'COVER_NOT_USABLE');
    }

    public static function primaryArtistRequired(): self
    {
        return new self(
            'A release needs at least one PRIMARY artist. Removing the last one would leave a '
                .'package no store can attribute.',
            'PRIMARY_ARTIST_REQUIRED',
        );
    }
}
