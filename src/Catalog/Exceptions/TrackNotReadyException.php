<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;
use SaniTube\Catalog\Models\Track;

final class TrackNotReadyException extends DomainException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(Track $track, array $problems): self
    {
        return new self(sprintf(
            'Track [%s] cannot be marked READY: %s',
            $track->uuid,
            implode(' ', $problems),
        ));
    }
}
