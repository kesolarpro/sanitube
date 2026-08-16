<?php

declare(strict_types=1);

namespace SaniTube\Identity\Exceptions;

use RuntimeException;

/**
 * A password reset the platform refused.
 *
 * Only two reasons, and the shape of that list is the design.
 *
 * `invalidToken()` covers a token that is wrong, expired, already used, or
 * belongs to an account deactivated since the link was sent. Telling those
 * apart would say more about the account than somebody holding a bad token has
 * earned — and "that address exists, the token is just old" is exactly the
 * sentence an enumerator is fishing for.
 *
 * `throttled()` is the one thing said out loud, because it is a fact about the
 * caller rather than about any account.
 *
 * There is deliberately no "unknown address" reason. Requesting a link never
 * refuses; it either sends one or quietly does not.
 *
 * The messages are translation keys, not sentences.
 */
final class PasswordResetFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $availableInSeconds = 0,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(string $status = ''): self
    {
        // `$status` is Laravel's broker status. Accepted for a log and
        // deliberately not carried into `reason`, which is what the interface
        // renders.
        return new self('identity.reset.invalid', 'INVALID_TOKEN');
    }

    public static function throttled(int $availableInSeconds): self
    {
        return new self('identity.reset.throttled', 'THROTTLED', $availableInSeconds);
    }
}
