<?php

declare(strict_types=1);

namespace Tests\Feature\Deduplication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Deduplication\Enums\DuplicateBasis;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;
use SaniTube\Media\Fingerprints\FingerprintPoints;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use Tests\TestCase;

/**
 * DEDUP-001 — what the platform may conclude, and what it may only propose.
 *
 * The feature exists to stop the catalogue filling with the same recording
 * under four filenames. It must do that without ever being the reason a master
 * disappeared, which is why every test here is as interested in what is *not*
 * written as in what is.
 */
final class DuplicateEvaluationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function identical_bytes_are_recorded_as_an_exact_duplicate(): void
    {
        $first = $this->asset('master.wav', 'the same bytes exactly');
        $second = $this->asset('master-copy.wav', 'the same bytes exactly');

        $findings = $this->evaluate($second);

        $this->assertCount(1, $findings);

        $finding = $findings->first();
        $this->assertInstanceOf(DuplicateRelation::class, $finding);
        $this->assertSame(DuplicateLevel::ExactDuplicate, $finding->level);
        $this->assertSame(DuplicateBasis::Checksum, $finding->basis);
        $this->assertSame($first->id, $finding->related_asset_id);

        // Measured, and still nobody's decision.
        $this->assertTrue($finding->level->isMeasured());
        $this->assertSame(DuplicateDecision::Proposed, $finding->decision);
        $this->assertNull($finding->decided_at);

        // No acoustic number, because no acoustic comparison happened.
        // Reporting 1.0 would put a figure in the interface that no
        // measurement produced.
        $this->assertNull($finding->similarity());
    }

    #[Test]
    public function nothing_is_deleted_or_marked_redundant_by_the_evaluation(): void
    {
        // The property the whole feature is judged on.
        $first = $this->asset('master.wav', 'the same bytes exactly');
        $second = $this->asset('master-copy.wav', 'the same bytes exactly');

        $this->evaluate($second);

        $this->assertNotNull($first->fresh());
        $this->assertNotNull($second->fresh());
        $this->assertSame(2, Asset::query()->count());
    }

    #[Test]
    public function the_same_audio_in_a_different_encoding_is_proposed_as_the_same_recording(): void
    {
        // The case checksums cannot reach: a WAV and an MP3 of one master are
        // the same recording and are never byte-identical.
        [$original, $reEncoded] = $this->pair(flippedBitsPerWord: 2);

        $findings = $this->evaluate($reEncoded);

        $this->assertCount(1, $findings);

        $finding = $findings->first();
        $this->assertInstanceOf(DuplicateRelation::class, $finding);
        $this->assertSame(DuplicateLevel::SameRecording, $finding->level);
        $this->assertSame(DuplicateBasis::Fingerprint, $finding->basis);
        $this->assertSame($original->id, $finding->related_asset_id);

        // Proposed, not concluded -- it came from a threshold.
        $this->assertFalse($finding->level->isMeasured());
        $this->assertSame(DuplicateDecision::Proposed, $finding->decision);

        // The evidence is kept beside the verdict, so a reviewer can see why
        // they are being asked and a recalibration can re-decide without
        // re-reading a master.
        $this->assertEqualsWithDelta(0.9375, (float) $finding->similarity(), 0.0001);
        $this->assertSame(200, $finding->overlap_frames);
        $this->assertSame('fake:1', $finding->algorithm);
    }

    #[Test]
    public function a_weaker_agreement_is_only_worth_a_look(): void
    {
        [, $other] = $this->pair(flippedBitsPerWord: 6);

        $finding = $this->evaluate($other)->first();

        $this->assertInstanceOf(DuplicateRelation::class, $finding);
        $this->assertSame(DuplicateLevel::PossibleRelation, $finding->level);
        $this->assertEqualsWithDelta(0.8125, (float) $finding->similarity(), 0.0001);
    }

    #[Test]
    public function audio_that_merely_runs_the_same_length_is_not_a_finding(): void
    {
        // The failure mode that would make the queue useless. On a catalogue
        // of one artist's work, a great many tracks are about three and a half
        // minutes long, and proposing every pair of them would train whoever
        // reviews this to click through without reading.
        [, $unrelated] = $this->pair(flippedBitsPerWord: 12);

        $this->assertCount(0, $this->evaluate($unrelated));
        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    #[Test]
    public function running_the_evaluation_again_does_not_grow_the_queue(): void
    {
        // A nightly pass over the catalogue re-evaluates everything. If that
        // multiplied the findings, the review screen would be unusable by the
        // end of the first week.
        [, $reEncoded] = $this->pair(flippedBitsPerWord: 2);

        $this->evaluate($reEncoded);
        $this->evaluate($reEncoded);
        $this->evaluate($reEncoded);

        $this->assertSame(1, DuplicateRelation::query()->count());
    }

    #[Test]
    public function a_finding_somebody_has_answered_is_left_alone(): void
    {
        // Re-running must not reopen a decision, and must not refresh the
        // evidence underneath one -- a verdict beside numbers nobody decided
        // on is a verdict nobody can explain.
        [, $reEncoded] = $this->pair(flippedBitsPerWord: 2);

        $finding = $this->evaluate($reEncoded)->first();
        $this->assertInstanceOf(DuplicateRelation::class, $finding);

        $finding->forceFill([
            'decision' => DuplicateDecision::Rejected,
            'similarity_basis_points' => 1234,
            'decided_at' => now(),
        ])->save();

        $this->evaluate($reEncoded);

        $finding->refresh();
        $this->assertSame(DuplicateDecision::Rejected, $finding->decision);
        $this->assertSame(1234, $finding->similarity_basis_points);
    }

    #[Test]
    public function the_same_pair_seen_from_the_other_side_is_not_queued_twice(): void
    {
        // Direction is meaningful -- which of the two arrived second -- but the
        // pair is one thing to review.
        [$original, $reEncoded] = $this->pair(flippedBitsPerWord: 2);

        $this->evaluate($reEncoded);
        $this->evaluate($original);

        $this->assertSame(1, DuplicateRelation::query()->count());
    }

    #[Test]
    public function switching_acoustic_evaluation_off_keeps_the_checksum_findings(): void
    {
        // Byte identity is observed rather than inferred and costs nothing, so
        // turning off the expensive half must not turn off the free half.
        config(['deduplication.enabled' => false]);

        $this->asset('master.wav', 'identical bytes');
        $second = $this->asset('copy.wav', 'identical bytes');

        [, $reEncoded] = $this->pair(flippedBitsPerWord: 2);

        $this->assertCount(1, $this->evaluate($second));
        $this->assertCount(0, $this->evaluate($reEncoded));
    }

    #[Test]
    public function an_asset_with_no_fingerprint_produces_no_acoustic_finding(): void
    {
        // Absent is not evidence of difference. Chromaprint is missing on most
        // shared hosting, and the honest answer there is that these files have
        // not been compared -- not that they differ.
        $this->fingerprintedAsset('a.wav', FakeAudioFingerprinter::pointsFrom('shared audio'), 200);
        $bare = $this->asset('b.wav', 'different bytes, no fingerprint row');

        $this->assertCount(0, $this->evaluate($bare));
        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @return Collection<int, DuplicateRelation>
     */
    private function evaluate(Asset $asset): Collection
    {
        return $this->app->make(EvaluateAssetDuplicates::class)->for($asset);
    }

    /**
     * Two assets whose audio differs by a known number of bits per frame.
     *
     * Flipping `n` of each word's 32 bits puts the agreement at exactly
     * `1 - n/32`, which is what lets these tests name a threshold boundary
     * instead of hoping a fixture lands on the right side of one.
     *
     * @return array{Asset, Asset}
     */
    private function pair(int $flippedBitsPerWord): array
    {
        $points = FakeAudioFingerprinter::pointsFrom('a shared recording');
        $altered = array_map(
            static fn (int $word): int => $word ^ ((1 << $flippedBitsPerWord) - 1),
            $points,
        );

        return [
            $this->fingerprintedAsset('original.wav', $points, 200),
            $this->fingerprintedAsset('re-encoded.mp3', $altered, 200),
        ];
    }

    /**
     * @param  list<int>  $points
     */
    private function fingerprintedAsset(string $filename, array $points, int $duration): Asset
    {
        $asset = $this->asset($filename, $filename.'-'.bin2hex(random_bytes(8)));
        $encoded = FingerprintPoints::encode($points);

        AudioFingerprint::query()->create([
            'asset_id' => $asset->id,
            'algorithm' => 'fake:1',
            'tool' => 'fake',
            'tool_version' => '1',
            'fingerprint' => $encoded,
            'fingerprint_hash' => hash('sha256', 'fake:1:'.$encoded),
            'duration_seconds' => $duration,
            'computed_at' => now(),
        ]);

        return $asset;
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $this->streamOf($contents));
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
