<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\AceStep;

use RuntimeException;

/**
 * The engine could not be reached, or refused.
 *
 * Carries a machine-readable reason and never the underlying client message.
 * ACE-Step's own errors are `f"Error generating audio: {str(e)}"` around a
 * Python traceback, and an HTTP client exception quotes the request that
 * produced it — including, on a configured deployment, the endpoint. Neither
 * belongs anywhere a person can read.
 */
final class AceStepUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self(
            'The ACE-Step engine did not answer. Nothing was generated.',
            'ENGINE_UNREACHABLE',
        );
    }

    public static function refused(): self
    {
        return new self(
            'The ACE-Step engine refused the request. Nothing was generated.',
            'ENGINE_REFUSED',
        );
    }

    public static function malformed(): self
    {
        return new self(
            'The ACE-Step engine answered in a shape this adapter does not recognise.',
            'ENGINE_MALFORMED_RESPONSE',
        );
    }
}
