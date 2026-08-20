<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use RuntimeException;

/**
 * An edit the catalogue declined.
 *
 * Every one carries a machine-readable reason. The English is for a developer
 * reading a log; the interface translates the code, because a refusal a
 * reviewer sees in their own language is a refusal they can act on.
 */
final class TrackMetadataException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The track has been delivered.
     *
     * Its metadata is what a distributor was given, and editing it in place
     * would leave this platform describing a release differently from the shops
     * carrying it. Correcting a released track is a distribution act with its
     * own consequences, not a metadata edit.
     */
    public static function released(): self
    {
        return new self('A released track cannot be edited as metadata.', 'TRACK_RELEASED');
    }

    public static function titleRequired(): self
    {
        return new self('A track title cannot be empty.', 'TRACK_TITLE_REQUIRED');
    }

    public static function titleTooLong(): self
    {
        return new self('A track title is limited to 255 characters.', 'TRACK_TITLE_TOO_LONG');
    }

    /**
     * `language_code` is read by the distribution export, where a word instead
     * of a key is a rejected delivery.
     */
    public static function languageNotACode(): self
    {
        return new self('A language must be a two- or three-letter code.', 'TRACK_LANGUAGE_NOT_A_CODE');
    }
}
