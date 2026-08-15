<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;
use SaniTube\Localization\ContentLanguage;

final class TrackLanguageException extends DomainException
{
    public static function instrumentalMismatch(bool $isInstrumental, string $languageCode): self
    {
        return new self(sprintf(
            'An instrumental must use language [%s] and only an instrumental may: got is_instrumental=%s '
                .'with language [%s].',
            ContentLanguage::INSTRUMENTAL,
            $isInstrumental ? 'true' : 'false',
            $languageCode,
        ));
    }
}
