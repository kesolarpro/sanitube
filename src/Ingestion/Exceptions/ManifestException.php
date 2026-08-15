<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Exceptions;

use RuntimeException;

/**
 * The manifest as a whole cannot be used.
 *
 * Separate from a refused *row*, which is collected and reported rather than
 * thrown. These are the cases where there is nothing to salvage: no header, no
 * column saying what to import, the same field spelled twice so it is
 * ambiguous which one carries the data. Continuing past any of them would mean
 * importing something other than what the file describes.
 */
final class ManifestException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The manifest has a header but no rows, or is empty.');
    }

    /**
     * @param  list<string>  $accepted
     */
    public static function missingReferenceColumn(array $accepted): self
    {
        return new self(sprintf(
            'The manifest has no column naming what to import. One of [%s] is required — a filename '
                .'is not accepted as one, because a filename is not an identity.',
            implode(', ', $accepted),
        ));
    }

    public static function duplicateColumn(string $field): self
    {
        return new self(sprintf(
            'The manifest carries two columns meaning [%s]. Which one holds the data is not '
                .'something SaniTube may decide on the operator\'s behalf.',
            $field,
        ));
    }

    public static function tooManyRows(int $limit): self
    {
        return new self(sprintf(
            'The manifest has more than %d rows. Split it: it is read into memory, and a batch is a '
                .'unit somebody has to be able to inspect when it half-fails.',
            $limit,
        ));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('The manifest at [%s] could not be opened for reading.', $path));
    }
}
