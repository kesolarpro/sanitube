<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Services\FindFingerprintCandidates;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * What each asset sounds like, stored as evidence rather than a verdict.
 *
 * Two claims carry these tests, and both are about what is *not* stored:
 *
 *   - **The fingerprint is kept, not a score.** A similarity percentage is a
 *     decision made with today's threshold, and thresholds get recalibrated
 *     once real data arrives. Keeping the fingerprint means a hundred thousand
 *     comparisons can be re-decided without re-reading a master — and
 *     re-reading fifty thousand masters to change one number is the sort of
 *     thing nobody does, so the number never gets changed.
 *   - **Nothing here decides anything.** No duplicate is declared, nothing is
 *     merged, nothing is trashed. The candidate lookup answers one question:
 *     what is even worth comparing.
 *
 * And one about feasibility: comparing every fingerprint against every other
 * is 1.25 billion pairs at fifty thousand assets. Duration is the pre-filter
 * that makes acoustic deduplication possible at all, and
 * `the_candidate_search_is_bounded_by_duration_rather_than_scanning_everything`
 * is the test that keeps it.
 */
final class AudioFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private FakeAudioFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->fingerprinter = new FakeAudioFingerprinter;
        $this->app->instance(AudioFingerprinter::class, $this->fingerprinter);
    }

    // --------------------------------------------------------- taking one

    #[Test]
    public function a_fingerprint_records_the_tool_and_its_version_beside_the_value(): void
    {
        // Fingerprints from different algorithm versions are not reliably
        // comparable. Without the version stored, a recalibration cannot tell
        // which rows it may compare and which it must recompute.
        $fingerprint = $this->fingerprint($this->asset('master.wav', 'audio bytes'));

        $this->assertInstanceOf(AudioFingerprint::class, $fingerprint);
        $this->assertSame('fake', $fingerprint->tool);
        $this->assertSame('1', $fingerprint->tool_version);
        $this->assertSame('fake:1', $fingerprint->algorithm);
        $this->assertNotEmpty($fingerprint->fingerprint);
        $this->assertNotEmpty($fingerprint->computed_at);
    }

    #[Test]
    public function the_raw_fingerprint_is_stored_and_not_only_a_summary(): void
    {
        // The claim the whole table exists for.
        $this->fingerprinter->willReturn('AQABz0mUaEkSRZEGP-CPI', 214);

        $fingerprint = $this->fingerprint($this->asset('master.wav', 'audio'));

        $this->assertInstanceOf(AudioFingerprint::class, $fingerprint);
        $this->assertSame('AQABz0mUaEkSRZEGP-CPI', $fingerprint->fingerprint);
        $this->assertSame(214, $fingerprint->duration_seconds);
    }

    #[Test]
    public function fingerprinting_twice_does_not_decode_twice(): void
    {
        $asset = $this->asset('master.wav', 'audio');

        $first = $this->fingerprint($asset);
        $second = $this->fingerprint($asset);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, AudioFingerprint::query()->count());
    }

    // ------------------------------------------------- a missing capability

    #[Test]
    public function an_installation_without_the_tool_is_not_a_broken_installation(): void
    {
        // The ordinary case on shared hosting. The asset is stored, analysed
        // and promotable; it simply cannot be compared acoustically.
        $this->fingerprinter->unavailable();

        $this->assertNull($this->fingerprint($this->asset('master.wav', 'audio')));
        $this->assertSame(0, AudioFingerprint::query()->count());
    }

    #[Test]
    public function a_tool_that_fails_leaves_no_row_and_no_exception(): void
    {
        $this->fingerprinter->failing();

        $this->assertNull($this->fingerprint($this->asset('master.wav', 'audio')));
        $this->assertSame(0, AudioFingerprint::query()->count());
    }

    #[Test]
    public function a_failed_fingerprint_leaves_no_temporary_file_behind(): void
    {
        // The case that litters if nobody writes the `finally`. Over four
        // thousand files it fills a shared account's disk quota.
        $this->fingerprinter->failing();

        $before = count((array) glob(sys_get_temp_dir().'/sanitube-fp-*'));

        $this->fingerprint($this->asset('master.wav', 'audio'));

        $this->assertSame($before, count((array) glob(sys_get_temp_dir().'/sanitube-fp-*')));
    }

    // ------------------------------------------------------ finding candidates

    #[Test]
    public function the_candidate_search_is_bounded_by_duration_rather_than_scanning_everything(): void
    {
        // The test that keeps deduplication feasible. Two recordings of
        // different lengths are not the same recording, and that is what turns
        // fifty thousand rows into a handful before any fingerprint is read.
        $target = $this->row(duration: 200);

        $this->row(duration: 201);   // within 2%
        $this->row(duration: 196);   // within 2%
        $this->row(duration: 260);   // far outside
        $this->row(duration: 30);    // far outside

        $candidates = $this->app->make(FindFingerprintCandidates::class)->for($target);

        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing([201, 196], $candidates->pluck('duration_seconds')->all());
    }

    #[Test]
    public function the_tolerance_scales_with_length_rather_than_being_a_fixed_window(): void
    {
        // Two seconds is nothing across nine minutes and a great deal across
        // fifteen. A fixed window would either miss re-encodes of long tracks
        // or drag in every jingle in the library.
        $short = $this->row(duration: 15);
        $this->row(duration: 18);

        $this->assertCount(0, $this->app->make(FindFingerprintCandidates::class)->for($short));

        $long = $this->row(duration: 540);
        $this->row(duration: 548);

        $this->assertCount(1, $this->app->make(FindFingerprintCandidates::class)->for($long));
    }

    #[Test]
    public function fingerprints_from_a_different_algorithm_version_are_never_compared(): void
    {
        // Comparing them anyway produces confident nonsense.
        $target = $this->row(duration: 200);

        $this->row(duration: 200, algorithm: 'fake:2');

        $this->assertCount(0, $this->app->make(FindFingerprintCandidates::class)->for($target));
    }

    #[Test]
    public function the_candidate_list_is_capped_and_keeps_the_closest(): void
    {
        // Nobody reviews a hundred candidates for one file. If the list is
        // truncated, what survives has to be what was most likely to match.
        $target = $this->row(duration: 300);

        foreach (range(1, 40) as $offset) {
            $this->row(duration: 300 + $offset);
        }

        $candidates = $this->app->make(FindFingerprintCandidates::class)->for($target, limit: 5);

        $this->assertCount(5, $candidates);
        $this->assertSame([301, 302, 303, 304, 305], $candidates->pluck('duration_seconds')->all());
    }

    #[Test]
    public function a_candidate_shorter_than_the_target_is_ordered_rather_than_aborting_the_query(): void
    {
        // The regression, and it is an engine difference rather than a typo.
        // `duration_seconds` is an unsigned column, and MySQL and MariaDB
        // evaluate unsigned minus unsigned as unsigned: ordering by the
        // obvious `ABS(duration_seconds - 300)` underflows on the first
        // candidate *shorter* than the target and aborts the entire query with
        // SQLSTATE[22003] before `ABS` is ever reached. SQLite has no unsigned
        // arithmetic and quietly returns a negative number, so the obvious
        // version passed there and failed on every other engine in the matrix.
        //
        // Every other candidate query in this file happens to compare against
        // rows at or above the target, which is exactly why one test caught it
        // and the rest did not.
        $target = $this->row(duration: 300);

        $this->row(duration: 295);
        $this->row(duration: 298);
        $this->row(duration: 303);

        $candidates = $this->app->make(FindFingerprintCandidates::class)->for($target);

        // Closest first, measured across the target rather than away from it:
        // 298 is two seconds out, 303 is three, 295 is five.
        $this->assertSame([298, 303, 295], $candidates->pluck('duration_seconds')->all());
    }

    #[Test]
    public function identical_fingerprints_are_a_fast_path_and_not_an_identity(): void
    {
        $target = $this->row(duration: 200, fingerprint: 'AAAA');
        $same = $this->row(duration: 200, fingerprint: 'AAAA');
        $this->row(duration: 200, fingerprint: 'BBBB');

        $identical = $this->app->make(FindFingerprintCandidates::class)->identicalTo($target);

        $this->assertCount(1, $identical);
        $this->assertSame($same->id, $identical->first()?->id);

        // And the near-miss is still a candidate, because a one-sample
        // difference is still the same recording.
        $this->assertCount(2, $this->app->make(FindFingerprintCandidates::class)->for($target));
    }

    // ------------------------------------------------------------ fixtures

    private function fingerprint(Asset $asset): ?AudioFingerprint
    {
        return $this->app->make(FingerprintAsset::class)->handle($asset);
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $this->streamOf($contents));
    }

    private function row(int $duration, string $algorithm = 'fake:1', ?string $fingerprint = null): AudioFingerprint
    {
        $value = $fingerprint ?? 'fp-'.$duration.'-'.bin2hex(random_bytes(4));

        return AudioFingerprint::query()->create([
            'asset_id' => $this->asset('m-'.bin2hex(random_bytes(4)).'.wav', bin2hex(random_bytes(8)))->id,
            'algorithm' => $algorithm,
            'tool' => 'fake',
            'tool_version' => '1',
            'fingerprint' => $value,
            'fingerprint_hash' => hash('sha256', $algorithm.':'.$value),
            'duration_seconds' => $duration,
            'computed_at' => now(),
        ]);
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
