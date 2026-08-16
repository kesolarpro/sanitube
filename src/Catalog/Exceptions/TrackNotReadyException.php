<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Validation\ValidationIssue;

final class TrackNotReadyException extends DomainException
{
    /** @var list<ValidationIssue> */
    public array $problems = [];

    /**
     * The refusal, carrying what is wrong rather than only saying that
     * something is.
     *
     * The message is English and goes in a log. The issues are what a caller
     * acts on — CAT-002's track screen renders them through the same
     * translation table the release screen uses.
     *
     * @param  list<ValidationIssue>  $problems
     */
    public static function because(Track $track, array $problems): self
    {
        $exception = new self(sprintf(
            'Track [%s] cannot be marked READY: %s',
            $track->uuid,
            implode(' ', array_map(static fn (ValidationIssue $i): string => $i->message(), $problems)),
        ));

        $exception->problems = $problems;

        return $exception;
    }
}
