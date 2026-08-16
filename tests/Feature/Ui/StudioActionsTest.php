<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The four things a person can make the studio do.
 *
 * The claim that carries this ticket is the negative one: **selecting a result
 * never creates a Track.** Generated audio is exactly the material that most
 * needs somebody to listen before it enters the catalogue — it was produced by
 * a machine from a sentence — so it joins the same review queue as an imported
 * file, and a separate decision on a separate screen promotes it or does not.
 *
 * The rest is money and idempotence: starting a generation spends the
 * operator's money at a supplier, so it must be queued rather than made inline,
 * a MEMBER must not be able to cause it, and doing anything twice must not
 * produce two of anything.
 */
final class StudioActionsTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->useFakeProvider();

        Queue::fake();
    }

    // -------------------------------------------------------------- who may act

    #[Test]
    public function every_studio_action_is_behind_authentication(): void
    {
        $generation = $this->generation();

        $this->post('/studio/projects', ['name' => 'x'])->assertRedirect(route('login'));
        $this->post('/studio/generations', ['prompt' => 'x'])->assertRedirect(route('login'));
        $this->post('/studio/generations/'.$generation->uuid.'/cancel')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_may_watch_the_studio_and_spend_nothing(): void
    {
        $generation = $this->generation();
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/studio')->assertOk();

        $this->actingAs($member)->post('/studio/generations', ['prompt' => 'Ambient'])->assertForbidden();
        $this->actingAs($member)->post('/studio/projects', ['name' => 'Autumn'])->assertForbidden();
        $this->actingAs($member)->post('/studio/generations/'.$generation->uuid.'/cancel')->assertForbidden();

        $this->assertSame(1, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_member_is_told_why_rather_than_shown_a_missing_button(): void
    {
        $response = $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->get('/studio')
            ->assertOk();

        $studio = $this->inertiaProps($response->content())['studio'];

        // The provider is available; the account is not permitted. Two
        // different failures with two different fixes.
        $this->assertTrue($studio['provider']['available']);
        $this->assertFalse($studio['may_generate']);
    }

    #[Test]
    public function nobody_may_generate_when_no_provider_is_configured(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $response = $this->actingAs($this->user())->get('/studio')->assertOk();
        $studio = $this->inertiaProps($response->content())['studio'];

        $this->assertFalse($studio['provider']['configured']);
        $this->assertFalse($studio['may_generate']);
    }

    // ------------------------------------------------------------- generating

    #[Test]
    public function starting_a_generation_queues_the_request_rather_than_making_it(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => 'An ambient piece for autumn'])
            ->assertRedirect();

        $generation = MusicGeneration::query()->firstOrFail();

        $this->assertSame(MusicGenerationStatus::Queued, $generation->status);
        $this->assertSame('An ambient piece for autumn', $generation->prompt);

        // The row exists before the provider is called, and the call is queued.
        // A browser that gives up mid-request must not leave a paid job nobody
        // has a record of.
        Queue::assertPushed(SubmitMusicGenerationJob::class, 1);
    }

    #[Test]
    public function a_new_generation_has_no_commercial_rights_answer(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => 'Something'])
            ->assertRedirect();

        // Never inferred, not even optimistically at creation. Whether
        // generated audio may be sold depends on terms nobody has read yet.
        $this->assertSame(
            CommercialRightsStatus::Unknown,
            MusicGeneration::query()->firstOrFail()->commercial_rights_status,
        );
    }

    #[Test]
    public function an_instrumental_carries_no_lyrics_however_the_form_was_filled_in(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/generations', [
                'prompt' => 'Piano only',
                'lyrics' => 'a verse somebody typed before changing their mind',
                'instrumental' => true,
            ])
            ->assertRedirect();

        $generation = MusicGeneration::query()->firstOrFail();

        // Resolved in the domain rather than validated into an error: a person
        // who ticks "instrumental" after typing lyrics has changed their mind.
        $this->assertNull($generation->lyrics);
        $this->assertTrue((bool) $generation->instrumental);
    }

    #[Test]
    public function a_generation_can_be_attached_to_a_campaign(): void
    {
        $project = $this->project();

        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => 'Ambient', 'project' => $project->uuid])
            ->assertRedirect();

        $this->assertSame($project->getKey(), MusicGeneration::query()->firstOrFail()->project_id);
    }

    #[Test]
    public function a_finished_campaign_refuses_new_generations(): void
    {
        $project = $this->project(GenerationProjectStatus::Completed);

        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => 'Ambient', 'project' => $project->uuid])
            ->assertSessionHasErrors(['generation' => 'PROJECT_CLOSED']);

        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_generation_without_a_prompt_is_refused_by_the_form(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => '   '])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function a_generation_cannot_be_started_when_no_provider_is_configured(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $this->actingAs($this->user())
            ->post('/studio/generations', ['prompt' => 'Ambient'])
            ->assertSessionHasErrors(['generation' => 'PROVIDER_UNAVAILABLE']);

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    // ------------------------------------------------------------- cancelling

    #[Test]
    public function cancelling_closes_the_generation_locally(): void
    {
        $generation = $this->generation(MusicGenerationStatus::Processing);

        $this->actingAs($this->user())
            ->post('/studio/generations/'.$generation->uuid.'/cancel')
            ->assertRedirect();

        $generation->refresh();

        $this->assertSame(MusicGenerationStatus::Cancelled, $generation->status);
        $this->assertNotNull($generation->completed_at);
    }

    #[Test]
    public function a_finished_generation_cannot_be_cancelled(): void
    {
        $generation = $this->generation(MusicGenerationStatus::Completed);

        // "Cancelling" a completed generation would rewrite the history of
        // something that already happened, including audio already paid for.
        $this->actingAs($this->user())
            ->post('/studio/generations/'.$generation->uuid.'/cancel')
            ->assertSessionHasErrors(['generation' => 'NOT_CANCELLABLE']);

        $this->assertSame(MusicGenerationStatus::Completed, $generation->refresh()->status);
    }

    #[Test]
    public function cancelling_twice_is_refused_the_second_time_and_changes_nothing(): void
    {
        $generation = $this->generation(MusicGenerationStatus::Processing);
        $owner = $this->user();

        $this->actingAs($owner)->post('/studio/generations/'.$generation->uuid.'/cancel')->assertRedirect();
        $completedAt = $generation->refresh()->completed_at;

        $this->actingAs($owner)
            ->post('/studio/generations/'.$generation->uuid.'/cancel')
            ->assertSessionHasErrors(['generation' => 'NOT_CANCELLABLE']);

        $this->assertEquals($completedAt, $generation->refresh()->completed_at);
    }

    // --------------------------------------------------------- selecting audio

    #[Test]
    public function selecting_a_result_produces_a_candidate_and_never_a_track(): void
    {
        $result = $this->completedResultWithAudio();

        $this->actingAs($this->user())
            ->post('/studio/results/'.$result->uuid.'/select')
            ->assertRedirect();

        $candidate = TrackCandidate::query()->firstOrFail();

        $this->assertSame(IngestionSource::Generated, $candidate->source);

        // The claim this whole ticket rests on. A campaign must not be able to
        // write nine hundred rows into the catalogue overnight.
        $this->assertSame(0, Track::query()->count());

        // And it lands PENDING, so MED-001 analyses it like any other file.
        $this->assertSame(TrackCandidateStatus::Pending, $candidate->status);

        $result->refresh();
        $this->assertTrue((bool) $result->selected);
        $this->assertSame($candidate->getKey(), $result->candidate_id);
    }

    #[Test]
    public function selecting_the_same_result_twice_creates_one_candidate_and_one_asset(): void
    {
        $result = $this->completedResultWithAudio();
        $owner = $this->user();

        $this->actingAs($owner)->post('/studio/results/'.$result->uuid.'/select')->assertRedirect();
        $this->actingAs($owner)->post('/studio/results/'.$result->uuid.'/select')->assertRedirect();

        // The retry a double-submitted form produces. Re-selecting returns what
        // is already there rather than fetching the audio again.
        $this->assertSame(1, TrackCandidate::query()->count());
        $this->assertDatabaseCount('assets', 1);
    }

    #[Test]
    public function a_result_cannot_be_taken_while_the_generation_is_still_running(): void
    {
        $result = $this->completedResultWithAudio(MusicGenerationStatus::Processing);

        // Fetching mid-flight would take audio that is not final.
        $this->actingAs($this->user())
            ->post('/studio/results/'.$result->uuid.'/select')
            ->assertSessionHasErrors(['generation' => 'GENERATION_NOT_COMPLETE']);

        $this->assertSame(0, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_result_with_no_audio_is_refused_rather_than_becoming_an_empty_candidate(): void
    {
        $generation = $this->generation(MusicGenerationStatus::Completed);

        $result = MusicGenerationResult::query()->create([
            'generation_id' => $generation->getKey(),
            'provider_result_id' => 'empty',
            'source_reference' => null,
            'selected' => false,
        ]);

        // A finished generation with nothing in it is a provider fault, not an
        // empty recording.
        $this->actingAs($this->user())
            ->post('/studio/results/'.$result->uuid.'/select')
            ->assertSessionHasErrors(['generation' => 'RESULT_HAS_NO_AUDIO']);

        $this->assertSame(0, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_member_cannot_take_audio_into_the_review_queue(): void
    {
        $result = $this->completedResultWithAudio();

        $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->post('/studio/results/'.$result->uuid.'/select')
            ->assertForbidden();

        $this->assertSame(0, TrackCandidate::query()->count());
        $this->assertDatabaseCount('assets', 0);
    }

    // ------------------------------------------------------------- campaigns

    #[Test]
    public function a_new_campaign_starts_as_a_draft(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/projects', ['name' => 'Autumn instrumentals', 'target_track_count' => 40])
            ->assertRedirect();

        $project = GenerationProject::query()->firstOrFail();

        // Nothing has been generated; ACTIVE would claim otherwise.
        $this->assertSame(GenerationProjectStatus::Draft, $project->status);
        $this->assertSame(40, $project->target_track_count);
        $this->assertTrue($project->status->acceptsGenerations());
    }

    #[Test]
    public function a_campaign_with_no_target_records_none_rather_than_zero(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/projects', ['name' => 'Open ended'])
            ->assertRedirect();

        // Null, not 0. A project with no target has none; 0 would say somebody
        // set a target of nothing.
        $this->assertNull(GenerationProject::query()->firstOrFail()->target_track_count);
    }

    #[Test]
    public function an_empty_default_is_stored_as_no_default(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/projects', ['name' => 'Blank defaults', 'default_style_prompt' => '   '])
            ->assertRedirect();

        // Otherwise every generation in the campaign inherits a style prompt of
        // nothing, which is not the same as inheriting no style prompt.
        $this->assertNull(GenerationProject::query()->firstOrFail()->default_style_prompt);
    }

    #[Test]
    public function a_campaign_without_a_name_is_refused(): void
    {
        $this->actingAs($this->user())
            ->post('/studio/projects', ['name' => '  '])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, GenerationProject::query()->count());
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps(string $html): array
    {
        $matched = preg_match('/data-page="([^"]+)"/', $html, $matches);
        $this->assertSame(1, $matched, 'The response carries no Inertia page object.');

        /** @var array<string, mixed> $page */
        $page = json_decode(html_entity_decode($matches[1]), true);

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }

    private function useFakeProvider(): void
    {
        config([
            'generation.default' => 'fake',
            // `none` stays declared, exactly as config/generation.php ships it.
            // Dropping it here would make "the disabled provider" unresolvable
            // and turn a fixture shortcut into a fake finding.
            'generation.providers' => [
                'none' => ['driver' => 'none'],
                'fake' => ['driver' => 'fake'],
            ],
        ]);

        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', new FakeMusicGenerationProvider);
    }

    private function project(GenerationProjectStatus $status = GenerationProjectStatus::Active): GenerationProject
    {
        return GenerationProject::query()->create(['name' => 'Autumn', 'status' => $status]);
    }

    private function generation(MusicGenerationStatus $status = MusicGenerationStatus::Queued): MusicGeneration
    {
        return MusicGeneration::query()->create([
            'provider' => 'fake',
            'status' => $status,
            'prompt' => 'An ambient piece',
            'language_code' => 'zxx',
            'instrumental' => true,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);
    }

    private function completedResultWithAudio(
        MusicGenerationStatus $status = MusicGenerationStatus::Completed,
    ): MusicGenerationResult {
        $reference = 'https://provider.example.test/audio/take-one.mp3';

        $reader = new InMemoryGeneratedAudioReader;
        $reader->put($reference, 'the generated audio itself');
        $this->app->instance(GeneratedAudioReader::class, $reader);

        $generation = $this->generation($status);

        return MusicGenerationResult::query()->create([
            'generation_id' => $generation->getKey(),
            'provider_result_id' => 'take-one',
            'source_reference' => $reference,
            'title' => 'Take one',
            'selected' => false,
        ]);
    }

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }
}
