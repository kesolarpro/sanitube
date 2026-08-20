<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\AI\AiManager;
use SaniTube\AI\Testing\FakeAiProvider;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Transcription\Jobs\TranscribeAssetJob;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Testing\FakeTranscriptionProvider;
use SaniTube\Transcription\TranscriptionManager;
use Tests\TestCase;

/**
 * UPL-002 — transcription and enrichment become things a person can ask for.
 *
 * Both routes existed before this ticket and neither had a caller. Transcription
 * was reachable only from the console, and a *first* metadata suggestion could
 * not be requested at all: the enrichment screen wires `accept`, `reject` and
 * `regenerate`, and regenerating requires a suggestion to already exist.
 *
 * **The screen tells a person why not, rather than greying a button out.** Both
 * steps cost money and both refuse for several different reasons — no supplier
 * configured, nothing to reason from, an answer already held, a file nobody
 * verified. The difference between "this installation has no AI account" and
 * "this file is not audio" is the difference between changing a setting and
 * choosing another file.
 */
final class OptionalWorkFromTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // -------------------------------------------------- what the screen says

    #[Test]
    public function with_no_supplier_the_screen_names_that_rather_than_going_quiet(): void
    {
        // The common case: a fresh installation has neither a transcription
        // supplier nor an AI account. A greyed-out button with no reason sends
        // somebody looking at the file when the answer is in the settings.
        $asset = $this->verifiedMaster();

        $work = $this->workFor($asset);

        $this->assertSame('TRANSCRIPTION_PROVIDER_UNAVAILABLE', $work['transcription']['refusal']);
        $this->assertSame('ENRICHMENT_MODEL_UNAVAILABLE', $work['enrichment']['refusal']);
    }

    #[Test]
    public function a_configured_installation_offers_transcription_on_an_audio_master(): void
    {
        $this->withTranscriptionProvider();

        $work = $this->workFor($this->verifiedMaster());

        $this->assertNull($work['transcription']['refusal'], 'Nothing should stand in the way here.');
        $this->assertFalse($work['transcription']['held']);
    }

    #[Test]
    public function enrichment_says_it_has_nothing_to_reason_from_until_there_is_a_transcript(): void
    {
        // Asking a model to describe a recording it has been told nothing about
        // produces a confident paragraph of invention, which is the worst thing
        // to put in a review queue: it looks exactly like an observation.
        $this->withAiProvider();

        $asset = $this->verifiedMaster();

        $this->assertSame('ENRICHMENT_NO_EVIDENCE', $this->workFor($asset)['enrichment']['refusal']);

        $this->transcribe($asset);

        $this->assertNull($this->workFor($asset)['enrichment']['refusal']);
    }

    #[Test]
    public function artwork_is_told_it_is_not_audio_rather_than_offered_transcription(): void
    {
        $this->withTranscriptionProvider();

        $artwork = $this->verified(AssetKind::Artwork, 'cover.jpg');

        $this->assertSame('TRANSCRIPTION_NOT_AUDIO', $this->workFor($artwork)['transcription']['refusal']);
    }

    #[Test]
    public function an_existing_transcript_is_reported_as_held(): void
    {
        $this->withTranscriptionProvider();

        $asset = $this->verifiedMaster();
        $this->transcribe($asset);

        $this->assertTrue($this->workFor($asset)['transcription']['held']);
    }

    #[Test]
    public function only_an_undecided_suggestion_counts_as_waiting(): void
    {
        // A suggestion somebody already accepted, rejected or superseded is not
        // waiting for anybody. Counting it would tell a reviewer there is work
        // in the queue that they have already done — and it would keep saying
        // so for the rest of the asset's life.
        $this->withAiProvider();

        $asset = $this->verifiedMaster();
        $transcript = $this->transcribe($asset);

        foreach ([SuggestionStatus::Accepted, SuggestionStatus::Rejected, SuggestionStatus::Superseded] as $decided) {
            $this->suggestion($asset, $transcript, $decided);
        }

        $this->assertFalse($this->workFor($asset)['enrichment']['waiting']);

        $this->suggestion($asset, $transcript, SuggestionStatus::Proposed);

        $this->assertTrue($this->workFor($asset)['enrichment']['waiting']);
    }

    // --------------------------------------------------- pressing the buttons

    #[Test]
    public function pressing_transcribe_queues_the_work(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the asset screen. The route existed
        // before this ticket and nothing pressed it.
        $this->withTranscriptionProvider();

        $asset = $this->verifiedMaster();

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            TranscribeAssetJob::class,
            static fn (TranscribeAssetJob $job): bool => $job->assetUuid === $asset->uuid,
        );
    }

    #[Test]
    public function pressing_suggest_queues_the_work(): void
    {
        $this->withAiProvider();

        $asset = $this->verifiedMaster();
        $this->transcribe($asset);

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/enrichment')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            SuggestMetadataJob::class,
            static fn (SuggestMetadataJob $job): bool => $job->assetUuid === $asset->uuid,
        );
    }

    #[Test]
    public function a_refusal_comes_back_as_a_code_and_queues_nothing(): void
    {
        // No supplier configured. The refusal is a code the interface
        // translates — the server never sends a sentence.
        $asset = $this->verifiedMaster();

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription')
            ->assertRedirect()
            ->assertSessionHasErrors(['transcription' => 'TRANSCRIPTION_PROVIDER_UNAVAILABLE']);

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    #[Test]
    public function a_read_only_member_cannot_spend_money_from_this_screen(): void
    {
        // Both steps are paid calls. The hidden button is presentation; the
        // route middleware is the check.
        $this->withTranscriptionProvider();
        $this->withAiProvider();

        $asset = $this->verifiedMaster();
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->post('/assets/'.$asset->uuid.'/transcription')->assertForbidden();
        $this->actingAs($member)->post('/assets/'.$asset->uuid.'/enrichment')->assertForbidden();

        Queue::assertNotPushed(TranscribeAssetJob::class);
        Queue::assertNotPushed(SuggestMetadataJob::class);
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function workFor(Asset $asset): array
    {
        $page = $this->actingAs($this->user())->get('/catalog/assets/'.$asset->uuid)->assertOk();

        /** @var array<string, mixed> $work */
        $work = $page->viewData('page')['props']['asset']['work'];

        return $work;
    }

    private function withTranscriptionProvider(): void
    {
        $manager = new TranscriptionManager([], 'fake');
        $manager->register('fake', new FakeTranscriptionProvider);

        $this->app->forgetInstance(TranscriptionManager::class);
        $this->app->instance(TranscriptionManager::class, $manager);
    }

    private function withAiProvider(): void
    {
        $manager = new AiManager([], 'fake');
        $manager->register('fake', new FakeAiProvider);

        $this->app->forgetInstance(AiManager::class);
        $this->app->instance(AiManager::class, $manager);
    }

    private function transcribe(Asset $asset): Transcript
    {
        return Transcript::query()->create([
            'asset_id' => $asset->id,
            'provider' => 'whisper',
            'provider_version' => '1',
            'model' => 'whisper-1',
            'detected_language' => 'fr',
            'text' => 'la la la',
            'covered_seconds' => 180,
            'transcribed_at' => now(),
        ]);
    }

    private function suggestion(Asset $asset, Transcript $transcript, SuggestionStatus $status): MetadataSuggestion
    {
        $suggestion = MetadataSuggestion::query()->create([
            'asset_id' => $asset->id,
            'task' => 'metadata_suggestion',
            'provider' => 'fake',
            'prompt_version' => 'v1',
            'transcript_id' => $transcript->id,
            'status' => SuggestionStatus::Proposed,
        ]);

        // Written after creation rather than passed in, because the model
        // defaults the status and a create() that quietly ignored the value
        // would leave every row PROPOSED — which is exactly what this test
        // exists to tell apart.
        $suggestion->forceFill(['status' => $status])->save();

        return $suggestion->refresh();
    }

    private function verifiedMaster(): Asset
    {
        return $this->verified(AssetKind::AudioMaster, 'master.wav');
    }

    private function verified(AssetKind $kind, string $filename): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'bytes-'.bin2hex(random_bytes(8)));
        rewind($stream);

        $stored = $assets->store($assets->register($kind, $filename), $stream);

        return $this->app->make(AssetVerificationService::class)->verify($stored->refresh());
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
