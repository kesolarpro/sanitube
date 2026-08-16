<?php

declare(strict_types=1);

namespace SaniTube\Observability\Exceptions;

use RuntimeException;

/**
 * Something an operator asked of a failed job that will not be done.
 *
 * Each carries a machine-readable `reason` the interface translates. The
 * English here is for a log, and the distinction between the reasons is the
 * point: "there is nothing there" and "I will not run that again" lead to
 * completely different next steps.
 */
final class FailedJobException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function gone(): self
    {
        return new self(
            'No failed job with that identifier. It was retried, deleted, or the page is stale.',
            'FAILED_JOB_GONE',
        );
    }

    public static function unknownKind(): self
    {
        return new self(
            'The payload does not name a job class, so there is no way to know whether running it '
                .'again is safe.',
            'FAILED_JOB_UNKNOWN_KIND',
        );
    }

    public static function notRetryable(string $job): self
    {
        return new self(
            sprintf(
                '[%s] has not declared itself safe to run twice, so it is not offered for retry. '
                    .'A job that got partway before failing may have already done the thing that '
                    .'must not happen twice.',
                $job,
            ),
            'FAILED_JOB_NOT_RETRYABLE',
        );
    }
}
