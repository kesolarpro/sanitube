<?php

declare(strict_types=1);

namespace SaniTube\Assets\Storage;

/**
 * What the uploader called the file — kept as a label, never as an address.
 *
 * The name a file arrives with is attacker-controlled input. It decides
 * nothing here: not where the object is stored, not what type it is, not
 * whether it is accepted. It is recorded so a human can recognise their own
 * upload, and it is sanitised because it will be printed back to them.
 *
 * Traversal is therefore not defended against so much as designed out. The
 * object key is built from a server-generated UUID
 * ({@see AssetObjectKeys}), so `../../etc/passwd.wav` has nowhere to go even
 * before it is stripped to `passwd.wav`.
 */
final readonly class OriginalFilename
{
    public const MAX_LENGTH = 255;

    /** Used when nothing survives sanitisation. */
    public const FALLBACK = 'unnamed';

    /** Long enough for every real extension, short enough to bound the key. */
    private const MAX_EXTENSION_LENGTH = 8;

    /**
     * The name, reduced to something safe to store and to display.
     */
    public static function sanitise(string $raw): string
    {
        // Windows separators first: basename() does not treat a backslash as a
        // separator on Linux, so a name from a Windows client would otherwise
        // survive whole.
        $name = basename(str_replace('\\', '/', $raw));

        // Control characters, including the newline that would let a filename
        // forge a second line in a log.
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        // Leading dots hide the file and spell the traversal segments; a name
        // is not allowed to start with one.
        $name = ltrim($name, '. ');

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            return self::FALLBACK;
        }

        return mb_substr($name, 0, self::MAX_LENGTH);
    }

    /**
     * The extension, if it is one worth keeping.
     *
     * Only ever a cosmetic suffix on the stored key so an object downloaded by
     * hand opens in something sensible. It is never consulted to decide what a
     * file is — that is what content sniffing and the declared kind are for —
     * and never used to decide whether a file is allowed.
     */
    public static function extension(string $raw): ?string
    {
        $name = self::sanitise($raw);
        $position = strrpos($name, '.');

        if ($position === false || $position === strlen($name) - 1) {
            return null;
        }

        $extension = strtolower(substr($name, $position + 1));

        // An allowlist of characters, not of extensions: anything outside
        // [a-z0-9] is dropped rather than escaped, because there is no reason
        // for an extension to contain anything else.
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        if ($extension === '' || strlen($extension) > self::MAX_EXTENSION_LENGTH) {
            return null;
        }

        return $extension;
    }
}
