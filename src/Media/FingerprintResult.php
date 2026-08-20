<?php

declare(strict_types=1);

namespace SaniTube\Media;

/**
 * What a fingerprinting tool said about one file.
 *
 * The **fingerprint itself**, not a score. That distinction is the whole point
 * of storing this: a similarity percentage is a decision made with today's
 * threshold, and thresholds get recalibrated once real data arrives. Keeping
 * the raw fingerprint means the platform can re-decide a hundred thousand
 * comparisons without re-reading a single master — and re-reading fifty
 * thousand masters to change one number is the sort of thing nobody does, so
 * the number never gets changed.
 *
 * `durationSeconds` is the tool's own reading, kept beside the fingerprint
 * rather than taken from the analysis row. Chromaprint's fingerprint is a
 * function of the audio it actually decoded; if that disagrees with what
 * ffprobe read, the comparison needs to know it was comparing something else.
 */
final readonly class FingerprintResult
{
    public function __construct(
        public string $fingerprint,
        public int $durationSeconds,
        public string $algorithm,
    ) {}

    /**
     * A short, indexable stand-in for the whole fingerprint.
     *
     * Two files whose fingerprints are byte-identical are the same decode of
     * the same audio, and that is worth finding with an index rather than a
     * scan. It is **not** an identity: two files that differ by one sample
     * produce different hashes and are still the same recording. Equality here
     * is a fast path, never the answer.
     */
    public function hash(): string
    {
        return hash('sha256', $this->algorithm.':'.$this->fingerprint);
    }
}
