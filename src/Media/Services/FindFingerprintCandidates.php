<?php

declare(strict_types=1);

namespace SaniTube\Media\Services;

use Illuminate\Support\Collection;
use SaniTube\Media\Models\AudioFingerprint;

/**
 * Which fingerprints are worth comparing against this one.
 *
 * **This class exists because the obvious implementation is impossible.**
 * Comparing every fingerprint against every other is O(n²): at fifty thousand
 * assets that is 1.25 billion pairs, and no amount of tuning makes that a
 * nightly job. It is not a slow query, it is the wrong shape of query.
 *
 * The way out is that **two recordings of different lengths are not the same
 * recording.** Duration is measured, indexed, and narrows fifty thousand rows
 * to a handful before a single fingerprint is looked at. Everything expensive
 * happens on that handful.
 *
 * The tolerance is proportional rather than fixed. A two-second difference is
 * nothing across a nine-minute recording and a great deal across a
 * fifteen-second one, and a fixed window would either miss re-encodes of long
 * tracks or drag in every jingle in the library.
 *
 * **This returns candidates, not matches.** Nothing here compares
 * fingerprints, scores similarity or decides anything is a duplicate. It
 * answers one question — what is even worth looking at — and the deduplication
 * decision is made elsewhere, by code that a human reviews the output of.
 */
final readonly class FindFingerprintCandidates
{
    /**
     * How far durations may differ and still be the same recording.
     *
     * Two percent absorbs re-encoding, container differences and a trimmed
     * silence; it does not absorb an edit.
     */
    public const TOLERANCE = 0.02;

    /**
     * Nobody reviews a hundred candidates for one file. A group this large is
     * a sign the tolerance is wrong, not a list to work through.
     */
    public const MAX_CANDIDATES = 25;

    /**
     * @return Collection<int, AudioFingerprint>
     */
    public function for(AudioFingerprint $fingerprint, int $limit = self::MAX_CANDIDATES): Collection
    {
        $duration = $fingerprint->duration_seconds;
        $window = max(1, (int) round($duration * self::TOLERANCE));

        return AudioFingerprint::query()
            // Same algorithm *and* version. Fingerprints from different
            // versions are not reliably comparable, and comparing them anyway
            // would produce confident nonsense.
            ->where('algorithm', $fingerprint->algorithm)
            ->whereKeyNot($fingerprint->getKey())
            ->whereBetween('duration_seconds', [
                // Clamped, so the lower bound is never a negative number
                // compared against an unsigned column.
                max(0, $duration - $window),
                $duration + $window,
            ])
            // Closest first: if the list is truncated, what survives is what
            // was most likely to match.
            //
            // Written as a CASE rather than the obvious `ABS(duration - ?)`
            // because `duration_seconds` is an *unsigned* column. MySQL and
            // MariaDB evaluate unsigned minus unsigned as unsigned, so a
            // candidate shorter than the target underflows and the engine
            // aborts the whole query with SQLSTATE[22003] before `ABS` is ever
            // reached. SQLite has no unsigned arithmetic and quietly returns a
            // negative number, which is why the obvious version passed on
            // SQLite and failed on every other engine in the matrix.
            //
            // Both branches subtract the smaller value from the larger one, so
            // the result is non-negative on every engine and no cast is needed.
            ->orderByRaw(
                'CASE WHEN duration_seconds >= ? THEN duration_seconds - ? ELSE ? - duration_seconds END',
                [$duration, $duration, $duration],
            )
            // A deterministic tiebreak. Two candidates equidistant from the
            // target — 199 and 201 against 200 — are otherwise ordered by
            // whatever the engine happens to return, which decides which one
            // survives the cap. Truncation must not be a coin toss.
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The fast path: fingerprints byte-identical to this one.
     *
     * The same decode of the same audio. **Not an identity** — files differing
     * by one sample hash differently and are still the same recording — which
     * is why this is a shortcut past the comparison, never a replacement for
     * it.
     *
     * @return Collection<int, AudioFingerprint>
     */
    public function identicalTo(AudioFingerprint $fingerprint): Collection
    {
        return AudioFingerprint::query()
            ->where('algorithm', $fingerprint->algorithm)
            ->where('fingerprint_hash', $fingerprint->fingerprint_hash)
            ->whereKeyNot($fingerprint->getKey())
            ->get();
    }
}
