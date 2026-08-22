<?php

declare(strict_types=1);

namespace SaniTube\Identity\Exceptions;

use RuntimeException;

/**
 * A refusal to change who may use this installation.
 *
 * USR-001. Every one carries a machine-readable reason, like the rest of the
 * platform's refusals: the English is for a developer reading a log, and the
 * interface renders the code in the reader's language.
 *
 * The four reasons are the whole rule set. There is no fifth, and none of them
 * is a matter of degree — a refusal about the ownership root that could be
 * argued with would be one somebody eventually argues around.
 */
final class UserAdministrationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The installation would be left with nobody who can administer it.
     *
     * The failure this prevents is total and unrecoverable from inside the
     * product: an installation whose last owner is inactive has no way back
     * except a shell on the server, which is exactly what the owner role
     * exists to make unnecessary.
     */
    public static function lastOwner(): self
    {
        return new self(
            'This is the last owner. Removing, deactivating or demoting them would leave the '
                .'installation with nobody who can administer it.',
            'LAST_OWNER',
        );
    }

    /**
     * Only an owner may make or unmake an owner.
     *
     * An administrator who could promote themselves would *be* an owner, and
     * the boundary between operating the platform and owning it would be
     * decoration.
     */
    public static function ownersAreOwnersBusiness(): self
    {
        return new self('Only an owner may change who else is an owner.', 'OWNER_ONLY');
    }

    /**
     * Somebody is administering themselves out of the room.
     *
     * Deliberately separate from {@see self::lastOwner()}: that one is about
     * the installation, this one is about the person, and they need different
     * sentences. Refused rather than confirmed, because the mistake is silent
     * — the screen reloads, the session is still valid, and nothing looks
     * wrong until the next sign-in.
     */
    public static function notYourself(): self
    {
        return new self('You cannot change your own role or deactivate your own account.', 'NOT_YOURSELF');
    }

    /**
     * An address already belongs to somebody.
     */
    public static function emailTaken(): self
    {
        return new self('An account already uses that email address.', 'EMAIL_TAKEN');
    }
}
