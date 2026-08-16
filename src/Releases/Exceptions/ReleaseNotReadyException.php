<?php

declare(strict_types=1);

namespace SaniTube\Releases\Exceptions;

use DomainException;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Releases\Models\Release;

/**
 * A release that was asked to be READY and is not.
 *
 * The message renders the issues in English, because that is what an exception
 * message is for. The *issues* travel too, so a caller that needs to say what
 * is wrong in somebody's own language has something better than a sentence to
 * work from.
 */
final class ReleaseNotReadyException extends DomainException
{
    /** @var list<ValidationIssue> */
    public array $problems = [];

    /**
     * @param  list<ValidationIssue>  $problems
     */
    public static function because(Release $release, array $problems): self
    {
        $exception = new self(sprintf(
            'Release [%s] cannot be marked READY: %s',
            $release->uuid,
            implode(' ', array_map(
                static fn (ValidationIssue $issue): string => $issue->message(),
                $problems,
            )),
        ));

        $exception->problems = $problems;

        return $exception;
    }
}
