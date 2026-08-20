<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprints;

/**
 * How alike two fingerprints are, and how that was established.
 *
 * A **measurement**, in the sense of ADR-0016's three levels of truth: it says
 * what fraction of the compared bits agreed and over how much audio, and it
 * says nothing at all about whether the two assets are the same recording.
 * That question needs a threshold, a threshold is a policy, and policy lives
 * where a human can change it without invalidating stored evidence.
 *
 * `notComparable` is a first-class answer rather than a similarity of zero.
 * Zero means "measured, and they share nothing"; not-comparable means the
 * measurement never happened — one side is missing, or the overlap was too
 * short to mean anything. Collapsing the two would let an absent fingerprint
 * read as positive evidence of difference.
 */
final readonly class FingerprintSimilarity
{
    private function __construct(
        public bool $comparable,
        /** 0.0 to 1.0 — the fraction of compared bits that agreed. */
        public float $similarity,
        /** How far one fingerprint had to slide to line up, in frames. */
        public int $offsetFrames,
        /** How much audio the answer is based on, in frames. */
        public int $overlapFrames,
        /** A machine-readable code, set only when the comparison did not happen. */
        public ?string $reason = null,
    ) {}

    public static function measured(float $similarity, int $offsetFrames, int $overlapFrames): self
    {
        return new self(true, $similarity, $offsetFrames, $overlapFrames);
    }

    public static function notComparable(string $reason): self
    {
        return new self(false, 0.0, 0, 0, $reason);
    }

    /**
     * The similarity in basis points, which is how it is stored.
     *
     * Integers, because a float column means a different number of stored
     * digits on each engine in the support matrix and a threshold comparison
     * that is true on one and false on another. Four digits is finer than the
     * measurement deserves and costs two bytes.
     */
    public function basisPoints(): int
    {
        return (int) round($this->similarity * 10000);
    }
}
