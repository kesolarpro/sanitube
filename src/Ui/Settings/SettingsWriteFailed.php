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
