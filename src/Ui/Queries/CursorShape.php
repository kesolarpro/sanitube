<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

/**
 * Whether an encoded cursor is one a given list could have issued.
 *
 * Laravel decodes a cursor into the ordering parameters it was built from and
 * then asks the paginator for each of them by name. A string that base64-decodes
 * to valid JSON but carries different parameters therefore reaches
 * `UnexpectedValueException` deep inside the paginator — a 500 for what is, at
 * worst, a mangled URL or a link copied from another screen.
 *
 * Extracted here after the third list needed it. A cursor is opaque to the
 * reader but not arbitrary: it either came from this ordering or it did not.
 */
final readonly class CursorShape
{
    /**
     * @param  list<string>  $orderingColumns
     */
    public static function carries(string $cursor, array $orderingColumns): bool
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false) {
            return false;
        }

        $parameters = json_decode($decoded, true);

        if (! is_array($parameters)) {
            return false;
        }

        // `_pointsToNextItems` is Laravel's own direction flag. Its absence
        // means the string was not produced by a cursor paginator at all.
        foreach ([...$orderingColumns, '_pointsToNextItems'] as $required) {
            if (! array_key_exists($required, $parameters)) {
                return false;
            }
        }

        return true;
    }
}
