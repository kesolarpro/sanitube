<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\MusicGeneration\AceStep\AceStepEngine;
use SaniTube\MusicGeneration\Enums\ExecutionMode;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Jobs\PollMusicGenerationJob;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\StorageGeneratedAudioReader;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Services\SelectMusicGenerationResult;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\FakeAceStepEngine;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\OpenProductionSlots;
use SaniTube\Production\Services\ProduceForProductionSlot;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Worker\Client\WorkerClient;
use Tests\TestCase;

/**
 * GEN-008 — the whole road, from an occasion to a candidate.
 *
 * The topology this ticket exists to make possible:
 *
 *     SaniTube Core  →(authenticated)→  Generation Worker  →  ACE-Step
 *                                              ↓ opens the local file
 *                                        shared storage
 *                     ←(storage key)←           ↓
 *     Core ingests ────────────────────────────┘
 *
 * **`output_path` never crosses a boundary.** Core is handed a storage key,
 * which it already knows how to ingest from any host, so an installation on
 * cPanel and an engine on a GPU box need share nothing but object storage.
 *
 * The worker is exercised through its real HTTP endpoint here rather than
 * called in-process: the boundary is the thing being proved, and a test that
 * skipped it would prove the two halves work when bolted together.
 */
final class SelfHostedGenerationPathTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private InMemoryStorageProvider $store;

    private FakeAceStepEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sanitube-path-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->engine = new FakeAceStepEngine;
        $this->app->instance(AceStepEngine::class, $this->engine);

        // This process is both Core and the worker, which is exactly how a
        // single-host installation runs. The token is what makes the endpoint
        // exist at all.
        config([
            'generation.acestep.output_root' => $this->root,
            'generation.acestep.endpoint' => 'http://engine.test',
            // WRK-001: the transport lives in config/worker.php now, one copy
            // for every capability.
            'worker.token' => 'a-worker-token',
            'worker.url' => 'http://worker.test',
            'worker.identity' => 'gpu-one',
            'generation.default' => 'acestep',
            'generation.preference' => ['acestep'],
            'generation.providers.acestep' => ['driver' => 'acestep'],
        ]);

        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->forgetInstance(SelectGenerationProvider::class);

        // Core's outbound call is routed into this same application's worker
        // endpoint, so the real middleware, the real form request and the real
        // controller all run.
        Http::fake(['worker.test/*' => fn ($request) => $this->callWorker($request)]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    // --------------------------------------------------- the production path

    #[Test]
    public function an_occasion_becomes_a_candidate_and_never_a_track(): void
    {
        // The mandate's first required proof, end to end: a ProductionSlot, one
        // provider call, the audio ingested, and no poll job.
        $slot = $this->dueSlot();

        $generation = $this->app->make(ProduceForProductionSlot::class)->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);

        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->app->make(SubmitMusicGeneration::class));

        $generation->refresh();

        $this->assertSame(MusicGenerationStatus::Completed, $generation->status);
        $this->assertSame(ExecutionMode::Synchronous, $generation->execution_mode);
        $this->assertNull($generation->provider_job_id, 'A synchronous provider invents no job identifier.');
        $this->assertSame(1, $this->engine->calls);

        // Synchronous: nothing to poll for.
        Queue::assertNotPushed(PollMusicGenerationJob::class);

        // The audio, taken into the review queue.
        $result = MusicGenerationResult::query()->where('generation_id', $generation->id)->firstOrFail();

        $candidate = $this->app->make(SelectMusicGenerationResult::class)->handle($result);

        $this->assertInstanceOf(TrackCandidate::class, $candidate);
        $this->assertInstanceOf(Asset::class, Asset::query()->find($candidate->asset_id));

        // **Generated audio never jumps to a Track.** It joins the same review
        // queue as an imported file, and somebody still has to listen.
        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function the_engines_path_never_reaches_the_database_or_the_browser(): void
    {
        // The rule this whole ticket is built around. `output_path` is an
        // implementation detail of the engine's host.
        $generation = $this->generateOne();

        $result = MusicGenerationResult::query()->where('generation_id', $generation->id)->firstOrFail();

        // The reference is a storage key behind our own scheme, and the
        // worker's disk appears nowhere in it.
        $this->assertStringStartsWith(StorageGeneratedAudioReader::SCHEME, (string) $result->source_reference);
        $this->assertStringNotContainsString($this->root, (string) $result->source_reference);
        $this->assertStringNotContainsString($this->root, json_encode($result->raw ?? []) ?: '');

        // And the studio read model drops the reference entirely.
        $user = $this->owner();

        $response = $this->actingAs($user)->get('/studio/generations/'.$generation->uuid);

        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString($this->root, $body);
        $this->assertStringNotContainsString('sanitube-storage://', $body);
    }

    #[Test]
    public function the_worker_endpoint_does_not_exist_until_a_token_is_configured(): void
    {
        // Almost every installation is Core only. None of them should advertise
        // a generation worker, still less expose one that fell back to
        // something permissive.
        config(['worker.token' => '']);

        $this->postJson('/api/worker/jobs/music-generation', ['reference' => 'abc', 'prompt' => 'x'])
            ->assertNotFound();

        // The handshake is gone too. A worker that is not one advertises
        // nothing at all.
        $this->getJson('/api/worker')->assertNotFound();
    }

    #[Test]
    public function the_worker_endpoint_refuses_a_wrong_token(): void
    {
        $this->postJson('/api/worker/jobs/music-generation', ['reference' => 'abc', 'prompt' => 'x'], [
            'X-SaniTube-Worker-Token' => 'not-the-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function core_boots_and_generates_nothing_when_no_worker_is_configured(): void
    {
        // The ordinary installation: cPanel, no GPU, no worker. Everything that
        // is not generation carries on, and the studio renders.
        config(['worker.url' => '', 'worker.token' => '']);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->forgetInstance(SelectGenerationProvider::class);

        $provider = $this->app->make(MusicGenerationManager::class)->provider('acestep');

        $this->assertFalse($provider->isAvailable(), 'An unconfigured worker means no ACE-Step.');

        $this->actingAs($this->owner())
            ->get('/studio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('studio.provider.available', false));
    }

    #[Test]
    public function a_worker_that_cannot_generate_is_not_reported_as_a_working_provider(): void
    {
        // **Configured is not capable.** The worker is reachable and the token
        // is right, but its ACE-Step is not set up, so its handshake does not
        // offer MUSIC_GENERATION. Trusting configuration alone here would put a
        // generate button on a screen pointed at a worker that must refuse --
        // a false green, which is worse than a red one.
        config(['generation.acestep.endpoint' => '', 'generation.acestep.output_root' => '']);

        $this->getJson('/api/worker', ['X-SaniTube-Worker-Token' => 'a-worker-token'])
            ->assertOk()
            ->assertJsonMissing(['capabilities' => ['MUSIC_GENERATION']]);

        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->forgetInstance(WorkerClient::class);

        $provider = $this->app->make(MusicGenerationManager::class)->provider('acestep');

        $this->assertFalse($provider->isAvailable());

        // And the studio says so rather than offering the button.
        $this->actingAs($this->owner())
            ->get('/studio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('studio.provider.available', false));
    }

    #[Test]
    public function the_adapter_claims_only_what_the_verified_contract_documents(): void
    {
        // ADR-0018. `infer-api.py` exposes generation and a health check --
        // no stems, no extend, no remix, no cancellation, no webhook -- so the
        // adapter claims none of those.
        $provider = $this->app->make(MusicGenerationManager::class)->provider('acestep');
        $found = $this->app->make(ProviderCapabilities::class)->for($provider);

        $this->assertContains(GenerationCapability::TextToMusic, $found);
        $this->assertContains(GenerationCapability::LyricsToMusic, $found);
        $this->assertContains(GenerationCapability::Instrumental, $found);
        $this->assertContains(GenerationCapability::SelfHosted, $found);
        $this->assertContains(GenerationCapability::Synchronous, $found);

        foreach ([
            GenerationCapability::StemSeparation,
            GenerationCapability::Extend,
            GenerationCapability::Remix,
            GenerationCapability::Cancel,
            GenerationCapability::Webhook,
            GenerationCapability::AsyncPolling,
        ] as $absent) {
            $this->assertNotContains($absent, $found, $absent->value.' is not in the verified contract.');
        }
    }

    #[Test]
    public function a_worker_that_refuses_fails_the_generation_with_the_workers_own_reason(): void
    {
        // Passed through unchanged: it is a code from a closed set, never free
        // text, so an operator sees what actually happened on the far side.
        $this->engine->claiming($this->root.'/never-written.wav');

        $generation = $this->start();

        $submitted = $this->app->make(SubmitMusicGeneration::class)->handle($generation);

        $this->assertSame(MusicGenerationStatus::Failed, $submitted->status);
        $this->assertStringContainsString('OUTPUT_MISSING', (string) $submitted->failure_reason);
    }

    #[Test]
    public function a_redelivered_synchronous_generation_costs_one_render(): void
    {
        // The mandate's third required proof. Idempotency is anchored in
        // SaniTube's own generation identity, not in a provider reference a
        // synchronous engine never gives.
        $generation = $this->start();

        $submitter = $this->app->make(SubmitMusicGeneration::class);

        $submitter->handle($generation);
        $submitter->handle($generation->refresh());

        $this->assertSame(1, $this->engine->calls);
        $this->assertSame(1, MusicGenerationResult::query()->count());
    }

    #[Test]
    public function a_crash_after_the_render_does_not_render_again(): void
    {
        // The mandate's fourth. The worker completed and Core recorded it; a
        // redelivery of the same intent must not pay for a second one.
        $generation = $this->start();

        $this->app->make(SubmitMusicGeneration::class)->handle($generation);

        $this->assertSame(1, $this->engine->calls);

        // The job is redelivered, as a queue does after a worker dies.
        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->app->make(SubmitMusicGeneration::class));

        $this->assertSame(1, $this->engine->calls);
        $this->assertSame(1, MusicGenerationResult::query()->count());
    }

    // --------------------------------------------------------------- helpers

    /**
     * Route Core's outbound call into this application's own worker endpoint.
     *
     * The real middleware, form request and controller run, so the boundary is
     * proved rather than assumed.
     */
    private function callWorker(mixed $request): mixed
    {
        /** @var Request $request */
        $headers = ['X-SaniTube-Worker-Token' => (string) config('worker.token')];

        // Two routes on the boundary and the fake honours both: the handshake
        // is a GET and a job is a POST. Answering one as the other is how an
        // integration test quietly stops testing the thing it names.
        $response = $request->method() === 'GET'
            ? $this->getJson('/api/worker', $headers)
            : $this->postJson('/api/worker/jobs/music-generation', (array) $request->data(), $headers);

        return Http::response($response->json(), $response->getStatusCode());
    }

    private function generateOne(): MusicGeneration
    {
        $generation = $this->start();

        $this->app->make(SubmitMusicGeneration::class)->handle($generation);

        return $generation->refresh();
    }

    private function start(): MusicGeneration
    {
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(StartMusicGeneration::class)
            ->handle(prompt: 'a quiet ballad');
    }

    private function dueSlot(): ProductionSlot
    {
        $profile = $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
            'default_artist_id' => Artist::factory()->create()->id,
        ]);

        $this->app->make(WriteProductionPlan::class)->create($profile, [
            'name' => 'Autumn '.bin2hex(random_bytes(3)),
            'autonomy_mode' => AutonomyMode::AutonomousGeneration,
            'cadence_days' => 7,
            'target_track_count' => 10,
        ]);

        $opened = $this->app->make(OpenProductionSlots::class)->handle();
        $slot = end($opened);

        $this->assertInstanceOf(ProductionSlot::class, $slot);
        $this->assertSame(ProductionSlotStatus::Pending, $slot->status);

        return $slot;
    }

    private function owner(): User
    {
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user->refresh();
    }
}
