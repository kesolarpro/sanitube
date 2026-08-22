<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use RuntimeException;

/**
 * A settings change that did not happen.
 *
 * Two reasons, and the difference matters to whoever reads it. The first says
 * the file was not touched; the second says it was and has been put back. An
 * operator's next step is different in each case, and a single "could not
 * save" would leave them guessing which state their installation is in.
 */
final class SettingsWriteFailed extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * A credential was submitted by somebody who administers the platform but
     * does not own it.
     *
     * USR-001. The split is deliberate and narrow: an administrator changes
     * how a provider behaves — quotas, timeouts, which one is selected — and
     * an owner decides which *account* it spends against. Refused rather than
     * quietly dropped, because a form that reports success while ignoring the
     * field somebody typed into is worse than one that says no.
     */
    public static function secretsAreOwnersBusiness(): self
    {
        return new self(
            'Only an owner may change a credential.',
            'SETTINGS_SECRETS_ARE_OWNERS_BUSINESS',
        );
    }

    public static function notWritten(): self
    {
        return new self(
            'The .env file could not be written. It was backed up first and has been left exactly as it was.',
            'SETTINGS_NOT_WRITTEN',
        );
    }

    public static function cacheNotRebuilt(): self
    {
        return new self(
            'The configuration cache could not be rebuilt, so the previous .env has been restored. A cache '
                .'that no longer matches the file is worse than no change at all.',
            'SETTINGS_CACHE_NOT_REBUILT',
        );
    }
}
