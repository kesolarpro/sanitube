<?php

declare(strict_types=1);

namespace SaniTube\Releases\Exceptions;

use DomainException;
use SaniTube\Releases\Models\Release;

final class ReleaseNotReadyException extends DomainException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(Release $release, array $problems): self
    {
        return new self(sprintf(
            'Release [%s] cannot be marked READY: %s',
            $release->uuid,
            implode(' ', $problems),
        ));
    }
}
