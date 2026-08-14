<?php

declare(strict_types=1);

namespace SaniTube\Localization\Exceptions;

use InvalidArgumentException;
use SaniTube\Localization\ContentLanguage;

final class InvalidContentLanguageException extends InvalidArgumentException
{
    public static function for(string $code): self
    {
        return new self(sprintf(
            'Invalid content language [%s]. Expected an ISO 639-1 code, or one of: %s, %s, %s.',
            $code,
            ContentLanguage::INSTRUMENTAL,
            ContentLanguage::UNKNOWN,
            ContentLanguage::MULTIPLE,
        ));
    }
}
