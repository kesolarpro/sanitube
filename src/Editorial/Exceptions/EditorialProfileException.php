<?php

declare(strict_types=1);

namespace SaniTube\Editorial\Exceptions;

use RuntimeException;

/**
 * A profile the platform declined to write.
 *
 * Machine-readable, like every refusal here; the English is for a log.
 */
final class EditorialProfileException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self('An editorial profile needs a name.', 'EDITORIAL_NAME_REQUIRED');
    }

    /**
     * Two imprints with the same slug is an ambiguity nothing can resolve
     * later — a plan naming one of them would have no way to say which.
     */
    public static function slugTaken(string $slug): self
    {
        return new self(sprintf('[%s] is already an editorial profile.', $slug), 'EDITORIAL_SLUG_TAKEN');
    }

    /**
     * `default_language` is copied onto releases and read by the distribution
     * export, where a word instead of a key is a rejected delivery.
     */
    public static function languageNotACode(): self
    {
        return new self('A default language must be a two- or three-letter code.', 'EDITORIAL_LANGUAGE_NOT_A_CODE');
    }
}
