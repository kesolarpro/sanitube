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
        $window = max(1, (int) round($fingerprint->duration_seconds * self::TOLERANCE));

        return AudioFingerprint::query()
            // Same algorithm *and* version. Fingerprints from different
            // versions are not reliably comparable, and comparing them anyway
            // would produce confident nonsense.
            ->where('algorithm', $fingerprint->algorithm)
            ->whereKeyNot($fingerprint->getKey())
            ->whereBetween('duration_seconds', [
                $fingerprint->duration_seconds - $window,
                $fingerprint->duration_seconds + $window,
            ])
            // Closest first: if the list is truncated, what survives is what
            // was most likely to match.
            ->orderByRaw('ABS(duration_seconds - ?)', [$fingerprint->duration_seconds])
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
