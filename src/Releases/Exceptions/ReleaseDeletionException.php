<?php

declare(strict_types=1);

namespace SaniTube\Releases\Exceptions;

use DomainException;
use SaniTube\Releases\Enums\ReleaseStatus;

final class ReleaseDeletionException extends DomainException
{
    public static function notDraft(string $uuid, ReleaseStatus $status): self
    {
        return new self(sprintf(
            'Release [%s] is [%s] and can no longer be deleted; only DRAFT releases may be.',
            $uuid,
            $status->value,
        ));
    }
}
