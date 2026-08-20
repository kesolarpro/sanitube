<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Media\Fingerprints\FingerprintComparator;
use SaniTube\Media\Fingerprints\FingerprintPoints;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use Tests\TestCase;

/**
 * DEDUP-001 — measuring how alike two fingerprints are.
 *
 * The arithmetic underneath the whole feature. If this is wrong, everything
 * above it is confidently wrong, which is the failure mode worth spending
 * tests on: a deduplication engine that is merely broken gets noticed, and one
 * that is subtly miscalibrated quietly proposes discarding masters.
 */
final class FingerprintComparatorTest extends TestCase
{
    private FingerprintComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comparator = new FingerprintComparator;
    }

    #[Test]
    public function a_fingerprint_is_identical_to_itself(): void
    {
        $points = FingerprintPoints::encode(FakeAudioFingerprinter::pointsFrom('a master'));

        $result = $this->comparator->compare($points, $points);

        $this->assertTrue($result->comparable);
        $this->assertSame(1.0, $result->similarity);
        $this->assertSame(0, $result->offsetFrames);
        $this->assertSame(10000, $result->basisPoints());
    }

    #[Test]
    public function two_unrelated_fingerprints_land_near_half_rather_than_near_zero(): void
    {
        // The number that makes every threshold in this feature make sense.
        // Independent bits agree half the time, so *unrelated* is 50%, not 0%.
        // A threshold set as though unrelated meant zero -- 0.5, say -- would
        // propose that every pair of files in the catalogue is related.
        $left = FingerprintPoints::encode(FakeAudioFingerprinter::pointsFrom('one recording'));
        $right = FingerprintPoints::encode(FakeAudioFingerprinter::pointsFrom('a completely different one'));

        $result = $this->comparator->compare($left, $right);

        $this->assertTrue($result->comparable);
        $this->assertGreaterThan(0.40, $result->similarity);
        $this->assertLessThan(0.60, $result->similarity);
    }

    #[Test]
    public function the_same_audio_starting_a_moment_later_still_matches(): void
    {
        // The case that decides whether this feature works at all. An encoder
        // that pads the front, a rip that starts a frame early, a master
        // exported with the leading silence trimmed -- all of them produce the
        // same recording, offset. Compared head-to-head they score like two
        // unrelated files, so without the alignment search the platform would
        // report that a file is not a duplicate of itself re-encoded.
        $points = FakeAudioFingerprinter::pointsFrom('a master');

        $whole = FingerprintPoints::encode($points);
        $trimmed = FingerprintPoints::encode(array_slice($points, 5));

        $result = $this->comparator->compare($whole, $trimmed);

        $this->assertSame(1.0, $result->similarity);
        $this->assertSame(5, $result->offsetFrames);
        $this->assertSame(195, $result->overlapFrames);
    }

    #[Test]
    public function a_single_flipped_bit_is_still_the_same_recording(): void
    {
        // Why the fingerprint is compared and not hashed. One sample of
        // difference is one bit of disagreement out of thousands, and an
        // equality test calls that "not a match".
        $points = FakeAudioFingerprinter::pointsFrom('a master');
        $altered = $points;
        $altered[0] ^= 1;

        $result = $this->comparator->compare(
            FingerprintPoints::encode($points),
            FingerprintPoints::encode($altered),
        );

        $this->assertGreaterThan(0.999, $result->similarity);
        $this->assertLessThan(1.0, $result->similarity);
    }

    #[Test]
    public function too_little_audio_is_refused_rather_than_scored(): void
    {
        // Not a similarity of zero. Zero means measured-and-different; this
        // means the measurement never happened, and collapsing the two would
        // let an unmeasurable pair read as positive evidence of difference.
        $short = FingerprintPoints::encode(FakeAudioFingerprinter::pointsFrom('brief', frames: 40));

        $result = $this->comparator->compare($short, $short);

        $this->assertFalse($result->comparable);
        $this->assertSame('FINGERPRINT_TOO_SHORT', $result->reason);
        $this->assertSame(0.0, $result->similarity);
    }

    #[Test]
    public function a_missing_fingerprint_is_refused_and_not_treated_as_difference(): void
    {
        $points = FingerprintPoints::encode(FakeAudioFingerprinter::pointsFrom('a master'));

        $result = $this->comparator->compare($points, '');

        $this->assertFalse($result->comparable);
        $this->assertSame('FINGERPRINT_EMPTY', $result->reason);
    }

    #[Test]
    public function garbage_in_a_stored_fingerprint_is_dropped_rather_than_read_as_zero(): void
    {
        // `(int) 'garbage'` is 0, and 0 is a legitimate fingerprint word.
        // Coercing would invent agreement on every bit of that frame.
        $this->assertSame([1, 2, 3], FingerprintPoints::decode('1,2,3'));
        $this->assertSame([1, 3], FingerprintPoints::decode('1,garbage,3'));
        $this->assertSame([], FingerprintPoints::decode(''));
        $this->assertSame([0, 5], FingerprintPoints::decode('0, 5'));
    }
}
