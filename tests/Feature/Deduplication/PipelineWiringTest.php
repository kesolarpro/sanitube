<?php

declare(strict_types=1);

namespace Tests\Feature\Deduplication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Events\AssetStored;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Events\AssetFingerprinted;
use SaniTube\Media\Jobs\FingerprintAssetJob;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use Tests\TestCase;

/**
 * PIPE-001 — the wiring, which is the whole ticket.
 *
 * MED-003 built fingerprinting, DEDUP-001 built comparison, DEDUP-002 and 003
 * built the decision and the screen. **None of it was ever called.** Nothing in
 * the application dispatched a fingerprint job or invoked the evaluator; the
 * only references outside the classes themselves were docblocks and tests. On a
 * real installation the duplicate queue would have been permanently empty, and
 * every one of those tickets would have passed its own tests forever.
 *
 * These are the tests that would have caught that, so they are written against
 * the seams rather than the services: what does storing an asset actually
 * cause, and what does a candidate appearing actually queue.
 */
final class PipelineWiringTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function storing_an_asset_finds_an_exact_duplicate_without_anything_asking_it_to(): void
    {
        // The case that needs no Chromaprint, is the commonest by far, and
        // would never have been found: evaluation only ever ran from a test.
        $this->asset('original.wav', 'the very same bytes');
        $second = $this->asset('copy.wav', 'the very same bytes');

        $relation = DuplicateRelation::query()->firstOrFail();

        $this->assertSame($second->id, $relation->asset_id);
        $this->assertSame('EXACT_DUPLICATE', $relation->level->value);
        $this->assertSame('PROPOSED', $relation->decision->value);
    }

    #[Test]
    public function an_installation_that_cannot_fingerprint_still_finds_the_byte_identical_ones(): void
    {
        // Most installations have no Chromaprint. If evaluation only followed
        // a fingerprint, they would find nothing at all -- which is why there
        // are two listeners and not one.
        $this->app->instance(AudioFingerprinter::class, (new FakeAudioFingerprinter)->unavailable());

        $this->asset('a.wav', 'identical bytes');
        $this->asset('b.wav', 'identical bytes');

        $this->assertSame(0, AudioFingerprint::query()->count(), 'Something fingerprinted without a fingerprinter.');
        $this->assertSame(1, DuplicateRelation::query()->count());
    }

    #[Test]
    public function a_candidate_appearing_queues_a_fingerprint_beside_the_analysis(): void
    {
        // Beside, not inside. FFmpeg and Chromaprint arrive independently, and
        // a server with one and not the other should get the half it can do.
        Queue::fake();

        $asset = $this->asset('master.wav', 'some audio');
        $candidate = $this->candidateFor($asset);

        TrackCandidateCreated::dispatch($candidate);

        Queue::assertPushed(
            FingerprintAssetJob::class,
            fn (FingerprintAssetJob $job): bool => $job->assetUuid === $asset->uuid,
        );
    }

    #[Test]
    public function a_fingerprint_announces_itself_so_deduplication_can_compare(): void
    {
        // The seam that lets Media stay ignorant of what a fingerprint is for.
        $asset = $this->asset('master.wav', 'some audio');

        $this->app->instance(AudioFingerprinter::class, new FakeAudioFingerprinter);

        Event::fake([AssetFingerprinted::class]);

        (new FingerprintAssetJob($asset->uuid))->handle($this->app->make(FingerprintAsset::class));

        Event::assertDispatched(AssetFingerprinted::class);
    }

    #[Test]
    public function nothing_is_announced_when_this_server_cannot_fingerprint(): void
    {
        // An ordinary state, not a failure -- and nothing new to compare, so
        // nothing to say.
        $asset = $this->asset('master.wav', 'some audio');

        $this->app->instance(AudioFingerprinter::class, (new FakeAudioFingerprinter)->unavailable());

        Event::fake([AssetFingerprinted::class]);

        (new FingerprintAssetJob($asset->uuid))->handle($this->app->make(FingerprintAsset::class));

        Event::assertNotDispatched(AssetFingerprinted::class);
    }

    // ------------------------------------------------------------- backfill

    #[Test]
    public function the_backfill_evaluates_a_library_that_predates_all_of_this(): void
    {
        // What an installation that has been running a while actually needs:
        // everything already imported, which is all of it.
        Event::fake([AssetStored::class, AssetFingerprinted::class]);

        $this->asset('a.wav', 'shared bytes');
        $this->asset('b.wav', 'shared bytes');

        $this->assertSame(0, DuplicateRelation::query()->count(), 'The fixture evaluated something already.');

        $this->artisan('sanitube:duplicates:evaluate')->assertSuccessful();

        $this->assertSame(1, DuplicateRelation::query()->count());
    }

    #[Test]
    public function the_backfill_writes_nothing_on_a_dry_run(): void
    {
        Event::fake([AssetStored::class, AssetFingerprinted::class]);

        $this->asset('a.wav', 'shared bytes');
        $this->asset('b.wav', 'shared bytes');

        $this->artisan('sanitube:duplicates:evaluate', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    #[Test]
    public function the_backfill_respects_the_stop_an_operator_just_pressed(): void
    {
        // A sweep that ignored the switch would be the first thing to make that
        // switch untrustworthy.
        Event::fake([AssetStored::class, AssetFingerprinted::class]);

        $this->asset('a.wav', 'shared bytes');
        $this->asset('b.wav', 'shared bytes');

        $this->artisan('sanitube:work:pause', ['--reason' => 'INCIDENT'])->assertSuccessful();
        $this->artisan('sanitube:duplicates:evaluate')->assertFailed();

        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    #[Test]
    public function the_backfill_does_not_even_look_at_the_trash(): void
    {
        // Asserted on the count the command reports, not on the findings.
        // `EvaluateAssetDuplicates` already refuses a trashed asset, so a test
        // that only checked for findings would pass with this filter deleted --
        // it did, when the mutation was tried. What the filter is actually for
        // is not loading rows a sweep has no business touching.
        Event::fake([AssetStored::class, AssetFingerprinted::class]);

        $this->asset('a.wav', 'shared bytes');
        $trashed = $this->asset('b.wav', 'shared bytes');

        $this->app->make(TrashAsset::class)->trash($trashed, $this->reviewer(), 'DUPLICATE');

        $this->artisan('sanitube:duplicates:evaluate')
            ->expectsOutputToContain('Evaluated 1 asset(s)')
            ->assertSuccessful();

        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    // ------------------------------------------------------------ fixtures

    private function candidateFor(Asset $asset): TrackCandidate
    {
        return TrackCandidate::query()->create([
            'asset_id' => $asset->id,
            'source' => IngestionSource::CloudImport,
            'status' => TrackCandidateStatus::NeedsReview,
            'original_filename' => 'master.wav',
        ]);
    }

    private function reviewer(): User
    {
        $user = User::query()->create([
            'name' => 'A reviewer',
            'email' => 'r-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $stream);
    }
}
