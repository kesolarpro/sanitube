<?php

declare(strict_types=1);

namespace SaniTube\Localization\Exceptions;

use InvalidArgumentException;

final class UnsupportedLocaleException extends InvalidArgumentException
{
    public static function malformed(string $candidate): self
    {
        return new self(sprintf('[%s] is not a well-formed language tag.', $candidate));
    }

    /**
     * @param  list<string>  $supported
     */
    public static function notSupported(string $code, array $supported): self
    {
        return new self(sprintf(
            'Locale [%s] is not supported. Supported locales: %s.',
            $code,
            implode(', ', $supported),
        ));
    }
}
