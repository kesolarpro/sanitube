<?php

declare(strict_types=1);

namespace SaniTube\Installer\Exceptions;

use RuntimeException;

/**
 * Why an install config file was refused.
 *
 * Every message here is shown to an operator standing at a shell, so each
 * says what to do — and none quotes a value from the file, because the file
 * holds a database password and possibly the owner's.
 */
final class InstallConfigurationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function missing(string $path): self
    {
        return new self(sprintf('The config file [%s] does not exist.', $path), 'CONFIG_MISSING');
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('The config file [%s] could not be read.', $path), 'CONFIG_UNREADABLE');
    }

    public static function tooOpen(string $path, int $mode): self
    {
        return new self(
            sprintf(
                'The config file [%s] is mode %o — readable or writable beyond its owner. It carries '
                    .'secrets; on a shared machine that mode means other accounts have already had the '
                    .'chance to read it. chmod 600 the file (and consider the secrets exposed) before '
                    .'running again.',
                $path,
                $mode,
            ),
            'CONFIG_TOO_OPEN',
        );
    }

    public static function unparseable(int $line): self
    {
        // The line number and never the line: the content of this file is
        // exactly what must not appear in a message.
        return new self(
            sprintf('Line %d of the config file is not a KEY=value line, a comment or blank.', $line),
            'CONFIG_UNPARSEABLE',
        );
    }

    /**
     * @param  list<string>  $known
     */
    public static function unknownKey(string $key, array $known): self
    {
        return new self(
            sprintf(
                '[%s] is not a key the installer accepts. A silently dropped key would surface three '
                    .'stages later as a database error, so unknown keys stop the run instead. '
                    .'Accepted: %s.',
                $key,
                implode(', ', $known),
            ),
            'CONFIG_UNKNOWN_KEY',
        );
    }
}
