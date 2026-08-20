<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprints;

/**
 * The storage form of a raw acoustic fingerprint.
 *
 * A Chromaprint fingerprint is a sequence of 32-bit words, one per frame of
 * roughly an eighth of a second, and each bit of each word is a separate
 * observation about the audio at that instant. **That structure is the whole
 * reason two recordings can be compared at all**: similarity is the fraction
 * of those bits that agree, so anything that destroys the per-bit alignment
 * destroys the ability to compare.
 *
 * Which is why the raw words are stored rather than the base64 form `fpcalc`
 * prints by default. The compressed form is smaller and is what AcoustID's API
 * accepts, but it is entropy-coded: two fingerprints that differ by one bit
 * compress to strings that differ everywhere. It can be tested for equality
 * and nothing else, and equality was never the interesting question — a WAV
 * and an MP3 of the same master are the same recording and are never
 * byte-identical.
 *
 * The encoding here is deliberately dull: decimal integers separated by
 * commas. It survives every database in the support matrix as ordinary text,
 * it is legible in a dump when somebody is working out why two files matched,
 * and it needs no extension to read back.
 */
final readonly class FingerprintPoints
{
    /**
     * @param  list<int>  $points
     */
    public static function encode(array $points): string
    {
        return implode(',', $points);
    }

    /**
     * @return list<int>
     */
    public static function decode(string $encoded): array
    {
        if (trim($encoded) === '') {
            return [];
        }

        $points = [];

        foreach (explode(',', $encoded) as $point) {
            $point = trim($point);

            // Anything that is not a number is skipped rather than coerced.
            // `(int) 'garbage'` is 0, and a zero word is a legitimate
            // fingerprint value — silently inventing one would make two
            // unrelated files agree on every bit of that frame.
            if ($point === '' || ! ctype_digit(ltrim($point, '-'))) {
                continue;
            }

            $points[] = (int) $point;
        }

        return $points;
    }
}
