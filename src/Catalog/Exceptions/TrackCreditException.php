<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use RuntimeException;
use SaniTube\Catalog\Enums\TrackStatus;

/**
 * A change to a track's credits the platform will not make.
 *
 * Each carries a machine-readable `reason`, for the argument CAT-001 makes: a
 * 422 with no reason is what makes a caller guess.
 */
final class TrackCreditException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function primaryArtistRequired(): self
    {
        return new self(
            'A track needs at least one PRIMARY artist. A recording with nobody credited on it is '
                .'one no store will display and nobody can be paid for.',
            'TRACK_PRIMARY_ARTIST_REQUIRED',
        );
    }

    public static function notCreditable(TrackStatus $status): self
    {
        return new self(
            sprintf(
                'A track in [%s] cannot be recredited. Its recordings are outside SaniTube — a store '
                    .'is serving them under the credits it was given — and changing the local record '
                    .'would only make the two disagree.',
                $status->value,
            ),
            'TRACK_NOT_CREDITABLE',
        );
    }

    public static function unknownArtist(string $uuid): self
    {
        return new self(sprintf('No artist [%s].', $uuid), 'UNKNOWN_ARTIST');
    }
}
