<?php

declare(strict_types=1);

namespace SaniTube\Media\Exceptions;

use RuntimeException;

/**
 * A fingerprint that could not be taken.
 *
 * Never a reason to fail the thing that asked. A file without a fingerprint is
 * a file this platform cannot compare acoustically — it is still stored, still
 * analysed, still promotable. The absence is recorded so the operations screen
 * can say how much of the library is comparable, rather than implying all of
 * it is.
 */
final class FingerprintFailed extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function toolUnavailable(string $tool): self
    {
        return new self(
            sprintf('[%s] is not installed or not executable, so no fingerprint was taken.', $tool),
            'FINGERPRINT_TOOL_UNAVAILABLE',
        );
    }

    public static function toolFailed(string $tool, string $detail): self
    {
        return new self(
            sprintf('[%s] could not read the file: %s', $tool, $detail),
            'FINGERPRINT_TOOL_FAILED',
        );
    }

    /**
     * A worker refused, in its own machine-readable words.
     *
     * WRK-002. The code comes from a closed set this repository defines, so it
     * cannot carry a credential and it is the only useful thing an operator can
     * be told about a failure on the far side of a boundary.
     */
    public static function because(string $reason): self
    {
        return new self(
            sprintf('The fingerprint worker refused: %s.', $reason),
            $reason,
        );
    }

    public static function unreadableOutput(string $tool): self
    {
        return new self(
            sprintf('[%s] returned output this platform could not parse.', $tool),
            'FINGERPRINT_UNREADABLE_OUTPUT',
        );
    }
}
