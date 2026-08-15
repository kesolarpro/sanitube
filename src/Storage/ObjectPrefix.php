<?php

declare(strict_types=1);

namespace SaniTube\Storage;

use SaniTube\Storage\Exceptions\StorageOperationFailed;

/**
 * Normalises and polices the prefixes a listing may be scoped to.
 *
 * A prefix is the only thing standing between "list the staging area" and
 * "list everything", so the provider normalises it here and then filters its
 * own results against it. The caller is never the first layer that trusts the
 * scope — a sweep that has to verify its own listing is a sweep whose safety
 * depends on the caller remembering to.
 *
 * `staging` and `staging/` mean the same thing; `staging/../masters` means
 * nothing this platform will accept.
 */
final readonly class ObjectPrefix
{
    /**
     * @return string the prefix with exactly one trailing slash, or '' for the whole store
     */
    public static function normalise(string $prefix): string
    {
        $trimmed = trim($prefix, '/');

        if ($trimmed === '') {
            return '';
        }

        // Rejected rather than sanitised. A prefix that needs cleaning up is a
        // prefix whose author was confused about the boundary, and quietly
        // repairing it hides that.
        if (str_contains($trimmed, '..') || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw StorageOperationFailed::unsafePrefix($prefix);
        }

        return $trimmed.'/';
    }

    /**
     * Whether a key really is inside an already-normalised prefix.
     */
    public static function contains(string $normalisedPrefix, string $key): bool
    {
        return $normalisedPrefix === '' || str_starts_with($key, $normalisedPrefix);
    }
}
