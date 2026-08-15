<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Jobs\PollMusicGenerationJob;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\Models\ProviderTermsSnapshot;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;
use SaniTube\MusicGeneration\Services\SelectMusicGenerationResult;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Music generation, and the two things it must never do.
 *
 * It must never require a provider — no music API is available to this
 * platform, Suno included, and the Studio has to be demonstrable and testable
 * anyway. And it must never produce a Track. Generated audio is exactly the
 * material that most needs a person to listen before it enters the catalogue,
 * so it joins the same review queue as an imported file.
 */
final class MusicGenerationTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private FakeMusicGenerationProvider $provider;

    private InMemoryGeneratedAudioReader $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->provider = new FakeMusicGenerationProvider;
        $this->audio = new InMemoryGeneratedAudioReader;

        config(['generation.default' => 'fake']);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', $this->provider);
        $this->app->instance(GeneratedAudioReader::class, $this->audio);

        Queue::fake();
    }

    // ------------------------------------------------- without a provider

    #[Test]
    public function an_installation_with_no_provider_refuses_clearly_rather_than_breaking(): void
    {
        // The default state of this platform. Nothing else in SaniTube stops
        // working because generation is unavailable.
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        try {
            $this->starter()->handle(prompt: 'ambient piano');
            $this->fail('Generation must refuse without a provider.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_UNAVAILABLE', $exception->reason);
        }

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function the_disabled_provider_is_called_none_and_never_null(): void
    {
        // AI-001 learned this in CI: Laravel reads the literal string `null`
        // in a .env file as PHP null, so `null` as a configuration value means
        // "no value" rather than "the provider named null". Same rule here so
        // an operator does not have to learn two conventions.
        foreach ([null, '', '  ', 'null', 'NULL'] as $configured) {
            $this->assertSame(
                MusicGenerationManager::DISABLED,
                MusicGenerationManager::normaliseProviderName($configured),
            );
        }

        $this->assertSame('sunoo', MusicGenerationManager::normaliseProviderName('sunoo'));
    }

    // ------------------------------------------------------- the lifecycle

    #[Test]
    public function a_generation_is_recorded_before_the_provider_is_called(): void
    {
        // The same ordering ING-001 uses for assets: everything after this
        // point is resumable because the row already exists. A submit that
        // crashes leaves a retryable generation, not a provider job nobody owns.
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        $this->assertSame(MusicGenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->provider_job_id);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_generation_moves_from_queued_through_processing_to_completed(): void
    {
        $generation = $this->submitted('ambient piano');

        $this->assertSame(MusicGenerationStatus::Processing, $generation->status);
        $this->assertNotNull($generation->provider_job_id);

        $this->provider->startProcessing((string) $generation->provider_job_id);
        $generation = $this->poller()->handle($generation);
        $this->assertSame(MusicGenerationStatus::Processing, $generation->status);

        $this->provider->complete((string) $generation->provider_job_id);
        $generation = $this->poller()->handle($generation);

        $this->assertSame(MusicGenerationStatus::Completed, $generation->status);
        $this->assertNotNull($generation->completed_at);
        $this->assertSame(1, $generation->results()->count());
    }

    #[Test]
    public function a_provider_failure_is_recorded_with_its_reason(): void
    {
        $generation = $this->submitted('ambient piano');

        $this->provider->fail((string) $generation->provider_job_id, 'Content policy refusal.');
        $generation = $this->poller()->handle($generation);

        $this->assertSame(MusicGenerationStatus::Failed, $generation->status);
        $this->assertSame('Content policy refusal.', $generation->failure_reason);
    }

    #[Test]
    public function a_cancelled_generation_stops_being_polled(): void
    {
        $generation = $this->submitted('ambient piano');

        $this->assertTrue($this->provider->cancelGeneration((string) $generation->provider_job_id));
        $generation = $this->poller()->handle($generation);

        $this->assertSame(MusicGenerationStatus::Cancelled, $generation->status);
        $this->assertFalse($generation->status->isPollable());
    }

    #[Test]
    public function a_completed_job_that_returned_no_audio_is_a_failure_not_an_empty_track(): void
    {
        // Rare, real, and the case that would otherwise put a silent recording
        // in the review queue.
        $generation = $this->submitted('ambient piano');

        $this->provider->completeWithNothing((string) $generation->provider_job_id);
        $generation = $this->poller()->handle($generation);

        $this->assertSame(MusicGenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->results()->count());
    }

    #[Test]
    public function polling_is_bounded_so_a_silent_provider_costs_a_fixed_amount(): void
    {
        // A row left permanently QUEUED is a row nobody ever cleans up, and a
        // self-dispatching job with no bound never stops.
        config(['generation.poll.max_polls' => 3]);

        $generation = $this->submitted('ambient piano');

        foreach (range(1, 4) as $ignored) {
            $generation = $this->poller()->handle($generation);
        }

        $this->assertSame(MusicGenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('did not finish', (string) $generation->failure_reason);
    }

    #[Test]
    public function the_polling_job_stops_dispatching_itself_once_the_job_is_terminal(): void
    {
        $generation = $this->submitted('ambient piano');
        $this->provider->complete((string) $generation->provider_job_id);

        (new PollMusicGenerationJob($generation->uuid))->handle($this->poller());

        Queue::assertNotPushed(PollMusicGenerationJob::class);
    }

    #[Test]
    public function polling_an_unsubmitted_generation_asks_the_provider_nothing(): void
    {
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        $this->assertSame($generation->status, $this->poller()->handle($generation)->status);
        $this->assertSame(0, $generation->refresh()->poll_count);
    }

    // ------------------------------------------------------ several takes

    #[Test]
    public function one_request_can_produce_several_takes_and_all_are_kept(): void
    {
        $this->provider->producing(3);
        $generation = $this->completedGeneration();

        $this->assertSame(3, $generation->results()->count());
    }

    #[Test]
    public function re_polling_a_finished_job_does_not_multiply_its_takes(): void
    {
        // Polling re-fetches the same list on every pass; a plain insert would
        // multiply the takes by the number of polls.
        $this->provider->producing(2);
        $generation = $this->completedGeneration();

        $this->poller()->handle($generation);
        $this->poller()->handle($generation->refresh());

        $this->assertSame(2, $generation->results()->count());
    }

    // -------------------------------------------------------- idempotence

    #[Test]
    public function submitting_the_same_generation_twice_does_not_create_a_second_provider_job(): void
    {
        $generation = $this->submitted('ambient piano');
        $jobId = $generation->provider_job_id;

        $again = $this->submitter()->handle($generation->refresh());

        $this->assertSame($jobId, $again->provider_job_id);
    }

    #[Test]
    public function the_database_refuses_two_generations_for_one_provider_job(): void
    {
        $generation = $this->submitted('ambient piano');

        $this->expectException(UniqueConstraintViolationException::class);

        MusicGeneration::query()->create([
            'provider' => $generation->provider,
            'provider_job_id' => $generation->provider_job_id,
            'status' => MusicGenerationStatus::Queued,
            'prompt' => 'another',
            'language_code' => ContentLanguage::UNKNOWN,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);
    }

    #[Test]
    public function unsubmitted_generations_coexist_because_nulls_stay_distinct(): void
    {
        $this->starter()->handle(prompt: 'one');
        $this->starter()->handle(prompt: 'two');
        $this->starter()->handle(prompt: 'three');

        $this->assertSame(3, MusicGeneration::query()->whereNull('provider_job_id')->count());
    }

    // ---------------------------------------------------------- selection

    #[Test]
    public function selecting_a_result_produces_a_verified_asset_and_a_generated_candidate(): void
    {
        $generation = $this->completedGeneration();
        $result = $generation->results()->firstOrFail();

        $candidate = $this->selector()->handle($result);

        $this->assertSame(IngestionSource::Generated, $candidate->source);
        $this->assertSame(AssetStatus::Verified, $candidate->asset->status);

        // PENDING, not READY: MED-001 analyses generated audio exactly as it
        // analyses an import. Generation gets no shortcut.
        $this->assertSame(TrackCandidateStatus::Pending, $candidate->status);

        $result->refresh();
        $this->assertTrue($result->selected);
        $this->assertSame($candidate->id, $result->candidate_id);
    }

    #[Test]
    public function generating_never_creates_a_track(): void
    {
        // The load-bearing negative of this ticket. A campaign that runs
        // overnight must not be able to write into the source of truth.
        $generation = $this->completedGeneration();

        $this->selector()->handle($generation->results()->firstOrFail());

        $this->assertSame(0, Track::query()->count());
        $this->assertSame(1, TrackCandidate::query()->count());
    }

    #[Test]
    public function selecting_the_same_result_twice_does_not_create_a_second_asset(): void
    {
        $generation = $this->completedGeneration();
        $result = $generation->results()->firstOrFail();

        $first = $this->selector()->handle($result);
        $second = $this->selector()->handle($result->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TrackCandidate::query()->count());
        $this->assertSame(1, Asset::query()->count());
    }

    #[Test]
    public function selecting_one_take_does_not_delete_the_others(): void
    {
        // A reviewer who changes their mind an hour later must still be able
        // to hear take two, and these are the only copies.
        $this->provider->producing(3);
        $generation = $this->completedGeneration();

        $this->selector()->handle($generation->results()->orderBy('id')->firstOrFail());

        $this->assertSame(3, $generation->results()->count());
        $this->assertSame(1, $generation->results()->where('selected', true)->count());
    }

    #[Test]
    public function a_second_take_can_be_selected_alongside_the_first(): void
    {
        // Two takes of the same prompt are two recordings, and a label may
        // legitimately want both.
        $this->provider->producing(2);
        $generation = $this->completedGeneration();

        foreach ($generation->results()->orderBy('id')->get() as $result) {
            $this->selector()->handle($result);
        }

        $this->assertSame(2, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_result_cannot_be_selected_before_its_generation_finishes(): void
    {
        $generation = $this->submitted('ambient piano');

        $result = MusicGenerationResult::query()->create([
            'generation_id' => $generation->id,
            'provider_result_id' => 'early',
            'source_reference' => 'early.mp3',
        ]);

        $this->expectException(GenerationException::class);

        $this->selector()->handle($result);
    }

    #[Test]
    public function a_result_with_no_audio_is_refused_rather_than_imported_empty(): void
    {
        $generation = $this->completedGeneration();

        $result = $generation->results()->firstOrFail();
        $result->forceFill(['source_reference' => null])->save();

        try {
            $this->selector()->handle($result);
            $this->fail('A result with no audio must not become a candidate.');
        } catch (GenerationException $exception) {
            $this->assertSame('RESULT_HAS_NO_AUDIO', $exception->reason);
        }
    }

    #[Test]
    public function a_provider_outage_mid_import_leaves_the_same_asset_for_the_retry(): void
    {
        // Same guarantee ING-001 gives: the asset id is written before any
        // bytes move, so a retry overwrites one address rather than orphaning
        // an object.
        $generation = $this->completedGeneration();
        $result = $generation->results()->firstOrFail();

        $this->audio->failWith();

        try {
            $this->selector()->handle($result);
        } catch (\Throwable) {
            // expected
        }

        $assetId = $result->refresh()->asset_id;
        $this->assertNotNull($assetId);

        $this->audio->recover();
        $candidate = $this->selector()->handle($result->refresh());

        $this->assertSame($assetId, $candidate->asset_id);
        $this->assertSame(1, Asset::query()->count());
    }

    // ------------------------------------------------ commercial provenance

    #[Test]
    public function commercial_rights_are_unknown_until_somebody_establishes_them(): void
    {
        // A generation existing says nothing about whether it may be sold.
        // Anything other than UNKNOWN here would put an unlicensed recording
        // in front of a distributor.
        $generation = $this->completedGeneration();

        $this->assertSame(CommercialRightsStatus::Unknown, $generation->commercial_rights_status);
        $this->assertFalse($generation->commercial_rights_status->permitsDistribution());
    }

    #[Test]
    public function a_terms_snapshot_records_what_a_person_read_and_where(): void
    {
        // Never scraped, never inferred. Terms change, and the recording made
        // in March is still in the catalogue in November.
        $snapshot = ProviderTermsSnapshot::query()->create([
            'provider' => 'fake',
            'plan' => 'Pro',
            'terms_version' => '2026-02',
            'effective_date' => '2026-02-01',
            'commercial_use_allowed' => true,
            'ownership_notes' => 'Full ownership on paid plans.',
            'source_reference' => 'https://example.invalid/terms',
            'captured_at' => now(),
        ]);

        $generation = $this->completedGeneration();
        $generation->forceFill([
            'terms_snapshot_id' => $snapshot->id,
            'commercial_rights_status' => CommercialRightsStatus::Allowed,
        ])->save();

        $this->assertSame($snapshot->id, $generation->refresh()->termsSnapshot?->id);
        $this->assertTrue($generation->commercial_rights_status->permitsDistribution());
    }

    #[Test]
    public function commercial_use_allowed_distinguishes_unknown_from_refused(): void
    {
        // Null means nobody established it; false means somebody read the
        // terms and they say no. Collapsing the two loses the difference
        // between "unchecked" and "not permitted".
        $unchecked = ProviderTermsSnapshot::query()->create([
            'provider' => 'fake', 'captured_at' => now(),
        ]);
        $refused = ProviderTermsSnapshot::query()->create([
            'provider' => 'fake', 'commercial_use_allowed' => false, 'captured_at' => now(),
        ]);

        $this->assertNull($unchecked->commercial_use_allowed);
        $this->assertFalse($refused->commercial_use_allowed);
    }

    // ---------------------------------------------------------- projects

    #[Test]
    public function a_project_supplies_defaults_an_individual_generation_may_override(): void
    {
        $project = GenerationProject::query()->create([
            'name' => 'Autumn ambient',
            'status' => GenerationProjectStatus::Active,
            'default_language' => 'fr',
            'default_style_prompt' => 'slow, warm, analogue',
        ]);

        $inherited = $this->starter()->handle(prompt: 'piano', project: $project);
        $this->assertSame('slow, warm, analogue', $inherited->style_prompt);
        $this->assertSame('fr', $inherited->language_code);

        $overridden = $this->starter()->handle(
            prompt: 'piano',
            project: $project,
            stylePrompt: 'fast, bright',
            languageCode: 'es',
        );
        $this->assertSame('fast, bright', $overridden->style_prompt);
        $this->assertSame('es', $overridden->language_code);
    }

    #[Test]
    public function a_finished_campaign_stops_accepting_generations(): void
    {
        // Adding quietly would make "how many did that campaign produce"
        // unanswerable.
        $project = GenerationProject::query()->create([
            'name' => 'Done', 'status' => GenerationProjectStatus::Completed,
        ]);

        try {
            $this->starter()->handle(prompt: 'piano', project: $project);
            $this->fail('A completed project must not accept generations.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROJECT_CLOSED', $exception->reason);
        }
    }

    #[Test]
    public function an_instrumental_carries_the_one_language_that_means_it(): void
    {
        // Same invariant CAT-001 enforces on promotion: ISO 639-2 already has
        // a code for "no linguistic content".
        $generation = $this->starter()->handle(prompt: 'piano', instrumental: true, languageCode: 'fr');

        $this->assertSame(ContentLanguage::INSTRUMENTAL, $generation->language_code);
        $this->assertNull($generation->lyrics, 'An instrumental cannot carry lyrics.');
    }

    // --------------------------------------------------------------- helpers

    private function starter(): StartMusicGeneration
    {
        return $this->app->make(StartMusicGeneration::class);
    }

    private function submitter(): SubmitMusicGeneration
    {
        return $this->app->make(SubmitMusicGeneration::class);
    }

    private function poller(): PollMusicGeneration
    {
        return $this->app->make(PollMusicGeneration::class);
    }

    private function selector(): SelectMusicGenerationResult
    {
        return $this->app->make(SelectMusicGenerationResult::class);
    }

    private function submitted(string $prompt): MusicGeneration
    {
        return $this->submitter()->handle($this->starter()->handle(prompt: $prompt));
    }

    private function completedGeneration(string $prompt = 'ambient piano'): MusicGeneration
    {
        $generation = $this->submitted($prompt);
        $this->provider->complete((string) $generation->provider_job_id);

        return $this->poller()->handle($generation);
    }
}
