<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\AI\AiManager;
use SaniTube\AI\Models\AiInvocation;
use SaniTube\AI\Testing\FakeAiProvider;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Transcription\Models\Transcript;
use Tests\TestCase;

/**
 * ENR-006 — the backlog nobody could reach.
 *
 * `SuggestWhenTranscribed` only ever fires forwards. Every other optional
 * feature in this platform has a way back over what was already there —
 * `sanitube:media:analyze`, `sanitube:transcription:backlog`,
 * `sanitube:duplicates:evaluate`, `sanitube:artwork:measure` — and enrichment
 * did not. A catalogue transcribed before ENR-002 existed would never have got
 * a single suggestion, and the only route back was to re-transcribe
 * everything: paying twice to fix an omission.
 *
 * **The command costs money**, so most of what is asserted here is what it
 * refuses to do.
 */
final class EnrichmentBacklogTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FakeAiProvider;

        $manager = new AiManager([], 'fake');
        $manager->register('fake', $this->model);

        $this->app->forgetInstance(AiManager::class);
        $this->app->instance(AiManager::class, $manager);

        config(['ai.default' => 'fake']);

        Queue::fake();
    }

    #[Test]
    public function it_queues_the_assets_that_have_evidence(): void
    {
        $withTranscript = $this->transcribedAsset();
        $this->asset(); // no transcript, so nothing to reason from

        $this->artisan('sanitube:enrichment:backlog')->assertSuccessful();

        Queue::assertPushed(SuggestMetadataJob::class, 1);
        Queue::assertPushed(
            SuggestMetadataJob::class,
            static fn (SuggestMetadataJob $job): bool => $job->assetUuid === $withTranscript->uuid,
        );
    }

    #[Test]
    public function an_asset_with_no_transcript_is_never_queued(): void
    {
        // Asking a model to describe a recording it has been told nothing about
        // produces a confident paragraph of invention — the worst thing to put
        // in a review queue, because it looks exactly like an observation.
        $this->asset();

        $this->artisan('sanitube:enrichment:backlog')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_installation_with_no_model_says_so_once_rather_than_queueing_nothing(): void
    {
        // "0 queued" would read as "nothing to do" on an installation whose
        // whole catalogue is waiting. Named plainly and exits non-zero.
        $this->transcribedAsset();

        // Configured but with no usable credentials — the state a fresh
        // installation is actually in, rather than one with no entry at all.
        $this->model->unavailable();

        $this->artisan('sanitube:enrichment:backlog')
            ->expectsOutputToContain('ENRICHMENT_MODEL_UNAVAILABLE')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dry_run_sends_nothing_and_queues_nothing(): void
    {
        $this->transcribedAsset();

        $this->artisan('sanitube:enrichment:backlog --dry-run')
            ->expectsOutputToContain('1 asset(s) would be queued')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_exhausted_ceiling_refuses_before_anything_is_queued(): void
    {
        // The scenario AiSpendGuard was written for, and the reason this
        // command asks it at all. Queueing into an exhausted ceiling produces
        // thousands of jobs that each wake a worker, consult the guard and
        // refuse — no cost at the vendor, a great deal on a shared host.
        config(['ai.limits.daily' => 1]);
        $this->recordInvocation();

        $this->transcribedAsset();

        $this->artisan('sanitube:enrichment:backlog')
            ->expectsOutputToContain('ceiling')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dry_run_reports_the_ceiling_rather_than_refusing_on_it(): void
    {
        // A dry run that stayed silent about this would tell an operator to
        // expect work a real run would decline.
        config(['ai.limits.daily' => 1]);
        $this->recordInvocation();

        $this->transcribedAsset();

        $this->artisan('sanitube:enrichment:backlog --dry-run')
            ->expectsOutputToContain('would be queued')
            ->expectsOutputToContain('ceiling')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_limit_bounds_the_pass_and_defaults_low(): void
    {
        // Low by default rather than everything, because the failure being
        // guarded against is four thousand jobs landing on a queue a cron
        // worker drains once a minute.
        for ($i = 0; $i < 3; $i++) {
            $this->transcribedAsset();
        }

        $this->artisan('sanitube:enrichment:backlog --limit=2')->assertSuccessful();

        Queue::assertPushed(SuggestMetadataJob::class, 2);
    }

    #[Test]
    public function a_trashed_asset_is_never_queued(): void
    {
        $asset = $this->transcribedAsset();
        $asset->forceFill(['trashed_at' => now()])->save();

        $this->artisan('sanitube:enrichment:backlog')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_limit_is_not_spent_on_rows_that_can_never_qualify(): void
    {
        // Why the trashed filter is in the query as well as in the eligibility
        // service. `--limit` bounds the rows *read*, so without it a trashed
        // asset ordered first consumes the only slot and the eligible one
        // behind it is never reached — the sweep reports success having queued
        // nothing, and running it again does the same thing for ever.
        $trashed = $this->transcribedAsset();
        $trashed->forceFill(['trashed_at' => now()])->save();

        $wanted = $this->transcribedAsset();

        $this->assertLessThan($wanted->id, $trashed->id, 'The trashed asset must be read first for this to prove anything.');

        $this->artisan('sanitube:enrichment:backlog --limit=1')->assertSuccessful();

        Queue::assertPushed(SuggestMetadataJob::class, 1);
        Queue::assertPushed(
            SuggestMetadataJob::class,
            static fn (SuggestMetadataJob $job): bool => $job->assetUuid === $wanted->uuid,
        );
    }

    #[Test]
    public function it_says_that_each_queued_asset_is_a_paid_call(): void
    {
        // The count above it is the number an operator will otherwise read as a
        // bill already paid, or as work already finished.
        $this->transcribedAsset();

        $this->artisan('sanitube:enrichment:backlog')
            ->expectsOutputToContain('paid call')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    private function transcribedAsset(): Asset
    {
        $asset = $this->asset();

        Transcript::query()->create([
            'asset_id' => $asset->id,
            'provider' => 'whisper',
            'provider_version' => '1',
            'model' => 'whisper-1',
            'detected_language' => 'fr',
            'text' => 'la la la',
            'covered_seconds' => 180,
            'transcribed_at' => now(),
        ]);

        return $asset;
    }

    private function asset(): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, 'master.wav'), $stream);
    }

    private function recordInvocation(): void
    {
        AiInvocation::query()->create([
            'provider' => 'fake',
            'model' => 'fake-1',
            'purpose' => 'metadata_suggestion',
            'prompt_version' => 'v1',
            'succeeded' => true,
            'attempted' => true,
        ]);
    }
}
