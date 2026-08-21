<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Exceptions;

use RuntimeException;

/**
 * Why a service file was not generated.
 *
 * The refused *value* is never echoed: it is by definition something that
 * failed a shape check, and the failure modes worth defending against —
 * newlines, directives, injection payloads — are exactly the strings a
 * terminal or a log should not be handed back.
 */
final class ProvisionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function invalid(string $label, string $expected): self
    {
        return new self(
            sprintf('The %s is not usable in a generated service file. Expected %s.', $label, $expected),
            'PROVISION_INVALID_INPUT',
        );
    }

    public static function missing(string $label, string $neededFor): self
    {
        return new self(
            sprintf('No %s was provided, and %s cannot be generated without one.', $label, $neededFor),
            'PROVISION_MISSING_INPUT',
        );
    }
}
