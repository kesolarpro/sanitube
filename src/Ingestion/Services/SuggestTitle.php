<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Assets\Storage\OriginalFilename;

/**
 * A guess at what a recording is called, from the only thing available.
 *
 * Filenames are a poor source of titles and this makes no pretence otherwise —
 * the result is stored in `suggested_title` and a human decides. It exists
 * because "01_-_final_MASTER_v3.wav" and nothing at all are both unhelpful,
 * and the first can at least be tidied into something recognisable.
 *
 * Deliberately conservative: it strips what is plainly noise — the extension,
 * a leading track number, separators used in place of spaces — and leaves the
 * rest alone. Cleverer rules invent titles that look authoritative and are
 * wrong, which is worse than an obvious guess.
 */
final readonly class SuggestTitle
{
    public static function from(string $filename): ?string
    {
        $name = OriginalFilename::sanitise($filename);

        // Drop the extension, if the last dot plausibly introduces one.
        $name = (string) preg_replace('/\.[a-z0-9]{1,8}$/i', '', $name);

        // A leading track number: "01 - ", "07_", "3.".
        $name = (string) preg_replace('/^\s*\d{1,3}\s*[-_.)]\s*/', '', $name);

        // Separators standing in for spaces.
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return $name === '' ? null : $name;
    }
}
