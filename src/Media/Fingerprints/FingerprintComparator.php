<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprints;

/**
 * How alike two acoustic fingerprints are.
 *
 * The measurement is the fraction of bits that agree between two fingerprints
 * once they have been lined up. Every frame contributes 32 independent
 * observations, so a few thousand bits of agreement is a strong statement
 * about the audio and a single mismatched frame is noise.
 *
 * **The alignment search is the part that is easy to leave out and wrong to.**
 * The same recording, encoded twice, routinely starts a fraction of a second
 * apart: an encoder pads the front, a rip begins a frame early, a master is
 * exported with the leading silence trimmed. Compared head-to-head those two
 * fingerprints agree on roughly half their bits, which is what two unrelated
 * files score. So one is slid against the other across a small window of
 * offsets and the best agreement wins. Without that step this class would
 * report that a file is not a duplicate of itself re-encoded, which is the
 * single most common thing it will ever be asked about.
 *
 * The window is small on purpose. Sliding far enough to match an arbitrary
 * excerpt turns "is this the same recording" into "does this recording contain
 * this audio anywhere", which is a different and much weaker question, and it
 * multiplies the cost of every comparison by the width of the search.
 *
 * **Nothing here decides anything.** It returns a number and the evidence
 * behind it; the threshold that turns a number into `SAME_RECORDING` lives in
 * configuration and is applied by the deduplication service, so that
 * recalibrating it re-reads stored fingerprints instead of stored masters.
 */
final readonly class FingerprintComparator
{
    /**
     * Each fingerprint word carries this many independent bits of evidence.
     */
    private const BITS_PER_FRAME = 32;

    public function compare(string $left, string $right): FingerprintSimilarity
    {
        $a = FingerprintPoints::decode($left);
        $b = FingerprintPoints::decode($right);

        if ($a === [] || $b === []) {
            return FingerprintSimilarity::notComparable('FINGERPRINT_EMPTY');
        }

        $minOverlap = $this->minimumOverlapFrames();

        // A comparison over a handful of frames is a coin toss that reports
        // itself as a percentage. Refusing is the honest answer.
        if (min(count($a), count($b)) < $minOverlap) {
            return FingerprintSimilarity::notComparable('FINGERPRINT_TOO_SHORT');
        }

        $best = null;
        $bestOffset = 0;
        $bestOverlap = 0;
        $maxOffset = $this->maximumOffsetFrames();

        for ($offset = -$maxOffset; $offset <= $maxOffset; $offset++) {
            $aStart = max(0, $offset);
            $bStart = max(0, -$offset);
            $overlap = min(count($a) - $aStart, count($b) - $bStart);

            if ($overlap < $minOverlap) {
                continue;
            }

            $errors = 0;

            for ($i = 0; $i < $overlap; $i++) {
                $errors += $this->bitsSet($a[$aStart + $i] ^ $b[$bStart + $i]);
            }

            $agreement = 1.0 - ($errors / ($overlap * self::BITS_PER_FRAME));

            if ($best === null || $agreement > $best) {
                $best = $agreement;
                $bestOffset = $offset;
                $bestOverlap = $overlap;
            }
        }

        // Every offset was rejected for too little overlap. Two fingerprints
        // long enough individually can still fail to overlap enough at any
        // alignment when one is barely longer than the window.
        if ($best === null) {
            return FingerprintSimilarity::notComparable('FINGERPRINT_NO_ALIGNMENT');
        }

        return FingerprintSimilarity::measured($best, $bestOffset, $bestOverlap);
    }

    /**
     * How many bits are set in a 32-bit word.
     *
     * The published divide-and-conquer count rather than
     * `substr_count(decbin(...), '1')`. This runs once per frame per candidate
     * per offset — low millions of times for one asset against a full
     * candidate list — and the string version turns a few milliseconds of
     * arithmetic into several seconds of allocation.
     */
    private function bitsSet(int $word): int
    {
        $word = $word - (($word >> 1) & 0x55555555);
        $word = ($word & 0x33333333) + (($word >> 2) & 0x33333333);
        $word = ($word + ($word >> 4)) & 0x0F0F0F0F;

        return ($word * 0x01010101) >> 24 & 0xFF;
    }

    /**
     * How far the fingerprints may be slid against each other.
     *
     * Clamped rather than trusted: zero would compare only head-to-head and
     * miss every re-encode, and a window as wide as the fingerprint would make
     * each comparison quadratic in its length.
     */
    private function maximumOffsetFrames(): int
    {
        $configured = (int) config('deduplication.fingerprint.max_offset_frames', 20);

        return max(0, min(200, $configured));
    }

    private function minimumOverlapFrames(): int
    {
        $configured = (int) config('deduplication.fingerprint.min_overlap_frames', 80);

        return max(1, $configured);
    }
}
