<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;

final class TrackDeletionException extends DomainException
{
    /**
     * @param  list<string>  $releases
     */
    public static function committedToReleases(string $trackUuid, array $releases): self
    {
        return new self(sprintf(
            'Track [%s] cannot be deleted: it is part of committed release(s) %s. '
                .'Those recordings are live at stores and removing them here would leave the catalogue '
                .'unable to account for what is in circulation.',
            $trackUuid,
            implode(', ', $releases),
        ));
    }
}
