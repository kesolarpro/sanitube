<?php

declare(strict_types=1);

namespace SaniTube\Identity\Exceptions;

use RuntimeException;

/**
 * A sign-in the platform refused.
 *
 * `invalidCredentials()` covers a wrong password, an unknown email *and* a
 * deactivated account. That is not laziness: distinguishing them tells an
 * attacker which addresses have accounts, and enumeration is how a credential
 * stuffing run gets targeted.
 *
 * The messages are translation keys, not sentences. Every string a person sees
 * goes through the locale files.
 */
final class AuthenticationFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $availableInSeconds = 0,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('identity.auth.failed', 'INVALID_CREDENTIALS');
    }

    public static function throttled(int $availableInSeconds): self
    {
        return new self('identity.auth.throttled', 'THROTTLED', $availableInSeconds);
    }
}
