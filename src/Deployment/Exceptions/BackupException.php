<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Exceptions;

use RuntimeException;

/**
 * A backup or restore the platform will not perform.
 *
 * Each carries a machine-readable `reason`, for the same argument the rest of
 * the platform makes: a refusal with no reason is a refusal a caller guesses
 * at. Restores in particular are refused far more often than they are
 * performed, and "no" without "because" is what makes somebody force it.
 */
final class BackupException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function destinationIsPublic(string $path): self
    {
        return new self(
            sprintf(
                'The backup destination [%s] is inside the web root. A backup holds every row of '
                    .'the catalogue; one served over HTTP is worse than no backup at all.',
                $path,
            ),
            'DESTINATION_IS_PUBLIC',
        );
    }

    public static function destinationNotWritable(string $path): self
    {
        return new self(sprintf('The backup destination [%s] cannot be written.', $path), 'DESTINATION_NOT_WRITABLE');
    }

    public static function notABackup(string $path): self
    {
        return new self(
            sprintf('There is no backup manifest at [%s].', $path),
            'NOT_A_BACKUP',
        );
    }

    public static function manifestUnreadable(string $why): self
    {
        return new self('The backup manifest could not be read: '.$why, 'MANIFEST_UNREADABLE');
    }

    public static function incomplete(string $entry): self
    {
        return new self(
            sprintf('The backup is incomplete: [%s] is missing. Restoring part of a backup is not restoring.', $entry),
            'BACKUP_INCOMPLETE',
        );
    }

    public static function corrupt(string $entry): self
    {
        return new self(
            sprintf(
                'The checksum for [%s] does not match the manifest. The backup is corrupt, and restoring '
                    .'it would write damage over working data.',
                $entry,
            ),
            'BACKUP_CORRUPT',
        );
    }

    /**
     * @param  list<string>  $missing
     */
    public static function schemaDrift(array $missing): self
    {
        return new self(
            sprintf(
                'This backup was taken against a different schema. Migrations it does not know about: %s. '
                    .'Restoring older rows into a newer schema silently is how a catalogue is corrupted.',
                implode(', ', array_slice($missing, 0, 5)),
            ),
            'SCHEMA_DRIFT',
        );
    }

    public static function unsafePath(string $path): self
    {
        return new self(
            sprintf('The manifest names a path outside the backup: [%s].', $path),
            'UNSAFE_PATH',
        );
    }

    public static function notConfirmed(): self
    {
        return new self(
            'A restore replaces the live database. It has to be confirmed explicitly.',
            'NOT_CONFIRMED',
        );
    }
}
