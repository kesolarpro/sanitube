<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Contracts\GenerationProvider;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\ExecutionMode;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\GenerationExecution;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\GenerationStatus;
use SaniTube\MusicGeneration\Jobs\PollMusicGenerationJob;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\ProviderResult;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * GEN-007 — a provider may answer with the audio.
 *
 * GEN-001 wrote the contract around one sentence: *"Generation is asynchronous
 * by nature."* It was true of every supplier then in view and was never
 * verified against one, because none was reachable. GEN-006's research found
 * that ACE-Step's own reference server blocks and returns the audio — no job
 * identifier, no status endpoint, no webhook, no cancellation.
 *
 * **The claim that carries this ticket is negative: a synchronous provider
 * never invents a job identifier.** Under the old contract an adapter had one
 * road — mint an identifier for a job that had already finished, store it, and
 * answer polls about it from memory. Every part of that is fiction, and fiction
 * in an adapter is load-bearing by the time anybody notices.
 *
 * See ADR-0019.
 */
final class GenerationExecutionModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        Queue::fake();
    }

    // ------------------------------------------------- no invented identifiers

    #[Test]
    public function a_synchronous_provider_finishes_without_ever_getting_a_job_identifier(): void
    {
        $this->useProvider(new AtOnceProvider);

        $generation = $this->start();
        $submitted = $this->submitter()->handle($generation);

        // Completed at once, and the whole point: nothing was invented.
        $this->assertSame(MusicGenerationStatus::Completed, $submitted->status);
        $this->assertNull($submitted->provider_job_id);
        $this->assertSame(ExecutionMode::Synchronous, $submitted->execution_mode);
        $this->assertNotNull($submitted->completed_at);

        // And the audio is there, through the same table the poller writes.
        $this->assertSame(1, MusicGenerationResult::query()->where('generation_id', $submitted->id)->count());
    }

    #[Test]
    public function a_synchronous_generation_is_never_polled(): void
    {
        // Nothing to ask about. Asking would mean a request for the status of a
        // job that never existed.
        $this->useProvider(new AtOnceProvider);

        $generation = $this->start();

        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        Queue::assertNotPushed(PollMusicGenerationJob::class);
        $this->assertFalse(ExecutionMode::Synchronous->isPollable());
    }

    #[Test]
    public function an_asynchronous_generation_is_polled_and_until_now_nothing_started_it(): void
    {
        // **A defect GEN-007 found.** PollMusicGenerationJob re-dispatches
        // itself while a generation is in flight, and nothing in the platform
        // ever dispatched the *first* one -- `grep` found it constructed only
        // in a test. An asynchronous generation reached PROCESSING and was
        // polled by nobody, sitting in flight for ever with a supplier holding
        // a finished job.
        $this->useProvider(new FakeMusicGenerationProvider('async'));

        $generation = $this->start();

        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        Queue::assertPushed(PollMusicGenerationJob::class);

        $generation->refresh();

        $this->assertSame(ExecutionMode::AsynchronousPolling, $generation->execution_mode);
        $this->assertNotNull($generation->provider_job_id);
    }

    #[Test]
    public function a_submission_that_failed_starts_no_polling(): void
    {
        // Nothing to poll for, and the reason the first guard is not merely
        // belt-and-braces for the synchronous case: a failed submission never
        // records an execution mode, so a check that looked only at the mode
        // would wave it through and queue a job per failure.
        $this->useProvider(new SilentAboutItsJobProvider);

        $generation = $this->start();

        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        $this->assertSame(MusicGenerationStatus::Failed, $generation->refresh()->status);
        $this->assertNull($generation->execution_mode);
        Queue::assertNotPushed(PollMusicGenerationJob::class);
    }

    #[Test]
    public function submitting_twice_asks_a_synchronous_supplier_once(): void
    {
        // Idempotency moved from `provider_job_id` to `submitted_at`, because a
        // synchronous provider never fills the first. A guard keyed on it would
        // have called such a supplier again on every redelivery -- and paid.
        $provider = new AtOnceProvider;
        $this->useProvider($provider);

        $generation = $this->start();

        $this->submitter()->handle($generation);
        $this->submitter()->handle($generation->refresh());

        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, MusicGenerationResult::query()->count());
    }

    #[Test]
    public function the_same_takes_returned_twice_do_not_become_two_rows(): void
    {
        // A redelivered synchronous call returns the same takes; the same take
        // must not become two rows an operator has to choose between.
        $provider = new AtOnceProvider;
        $this->useProvider($provider);

        $generation = $this->start();
        $this->submitter()->handle($generation);

        // Reopened by hand, as a crash between writing takes and completing
        // would leave it.
        $generation->refresh()->forceFill([
            'status' => MusicGenerationStatus::Queued,
            'submitted_at' => null,
        ])->save();

        $this->submitter()->handle($generation->refresh());

        $this->assertSame(2, $provider->calls);
        $this->assertSame(1, MusicGenerationResult::query()->count());
    }

    // ------------------------------------------------------ what a shape means

    #[Test]
    public function execution_shape_is_read_off_the_interface_and_not_declared(): void
    {
        $capabilities = $this->app->make(ProviderCapabilities::class);

        $this->assertContains(GenerationCapability::Synchronous, $capabilities->for(new AtOnceProvider));
        $this->assertNotContains(GenerationCapability::AsyncPolling, $capabilities->for(new AtOnceProvider));

        $this->assertContains(GenerationCapability::AsyncPolling, $capabilities->for(new FakeMusicGenerationProvider));
        $this->assertNotContains(GenerationCapability::Synchronous, $capabilities->for(new FakeMusicGenerationProvider));
    }

    #[Test]
    public function a_supplier_that_offers_both_shapes_gets_both_capabilities(): void
    {
        // Separate capabilities rather than one either-or field, precisely so
        // this is expressible.
        $capabilities = $this->app->make(ProviderCapabilities::class);
        $found = $capabilities->for(new EitherWayProvider);

        $this->assertContains(GenerationCapability::Synchronous, $found);
        $this->assertContains(GenerationCapability::AsyncPolling, $found);
    }

    #[Test]
    public function a_supplier_that_offers_both_is_asked_the_way_that_needs_no_second_call(): void
    {
        // Strictly better: nothing to poll, nothing to lose track of, and no
        // row left PROCESSING if the queue stops.
        $this->useProvider(new EitherWayProvider);

        $submitted = $this->submitter()->handle($this->start());

        $this->assertSame(ExecutionMode::Synchronous, $submitted->execution_mode);
        $this->assertNull($submitted->provider_job_id);
    }

    #[Test]
    public function an_execution_cannot_be_built_in_a_state_the_design_excludes(): void
    {
        // The value of the union is that the impossible states cannot be made,
        // so they are refused at construction rather than discovered three
        // services later.
        try {
            GenerationExecution::completed([]);
            $this->fail('A completed execution with no audio must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at least one result', $exception->getMessage());
        }

        try {
            GenerationExecution::inFlight('  ');
            $this->fail('An in-flight execution with no identifier must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('job identifier', $exception->getMessage());
        }

        try {
            GenerationExecution::inFlight('job-1', ExecutionMode::Synchronous);
            $this->fail('A synchronous execution cannot be in flight.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be in flight', $exception->getMessage());
        }
    }

    #[Test]
    public function an_asynchronous_provider_that_returns_no_identifier_is_a_failure_and_not_a_synchronous_answer(): void
    {
        // Before GEN-007 these were the same branch, and that sameness is what
        // would have forced a synchronous adapter to invent an identifier.
        $this->useProvider(new SilentAboutItsJobProvider);

        $submitted = $this->submitter()->handle($this->start());

        $this->assertSame(MusicGenerationStatus::Failed, $submitted->status);
        $this->assertNull($submitted->provider_job_id);
        $this->assertSame(0, MusicGenerationResult::query()->count());

        // And it says which fault it was. An asynchronous supplier that
        // answered without an identifier has a bug; a provider implementing
        // neither contract has not been finished. One sentence for both would
        // send an operator to read the wrong file.
        $this->assertStringContainsString('no job identifier', (string) $submitted->failure_reason);
    }

    #[Test]
    public function two_workers_handed_one_synchronous_generation_pay_once(): void
    {
        // **The guard the early return only looks like.** A redelivery finds
        // `submitted_at` set and stops before doing any work; a *concurrent*
        // worker holds an in-memory row from before the submission, so the
        // early return sees null and lets it through. The atomic claim is what
        // actually decides -- and it must guard on `submitted_at` too, because
        // a synchronous provider never fills `provider_job_id` and a claim
        // keyed on that column would wave the second worker straight through
        // to a second paid call.
        $provider = new AtOnceProvider;
        $this->useProvider($provider);

        $generation = $this->start();

        // Worker B's copy, taken before worker A ran.
        $stale = MusicGeneration::query()->whereKey($generation->getKey())->firstOrFail();

        $this->submitter()->handle($generation);

        $this->assertNull($stale->submitted_at, 'The copy is deliberately stale.');

        $this->submitter()->handle($stale);

        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, MusicGenerationResult::query()->count());
    }

    #[Test]
    public function only_two_places_in_the_platform_ask_what_shape_a_provider_has(): void
    {
        // And they ask for different reasons, which is why both are correct:
        //
        //   - `ProviderCapabilities` asks in order to *report* -- reading a
        //     capability off an interface is exactly what ADR-0019 says
        //     discovery should do;
        //   - `SubmitMusicGeneration` asks in order to *act*, and it is the
        //     only place that branches on the answer.
        //
        // A third would be how two parts of a platform start disagreeing about
        // what a supplier is. Asserted mechanically because this is a boundary
        // that erodes by somebody adding one convenient check.
        $branches = [];

        foreach (['src/MusicGeneration', 'src/Production', 'src/Ui', 'src/Api'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory)));

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($source, 'instanceof SynchronousMusicGenerationProvider')) {
                    $branches[] = $file->getFilename();
                }
            }
        }

        $branches = array_values(array_unique($branches));
        sort($branches);

        $this->assertSame(['ProviderCapabilities.php', 'SubmitMusicGeneration.php'], $branches);
    }

    // ------------------------------------------------------ the production path

    #[Test]
    public function the_studio_form_reaches_a_synchronous_supplier_through_the_same_route(): void
    {
        // WHO CALLS THIS IN PRODUCTION: POST /studio/generations ->
        // StudioActionController::startGeneration -> SubmitMusicGenerationJob
        // -> SubmitMusicGeneration::execute. The route did not change; what
        // changed is that it now reaches a supplier of either shape.
        $this->useProvider(new AtOnceProvider);

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);
        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        $this->actingAs($user->refresh())
            ->post('/studio/generations', ['prompt' => 'A quiet ballad'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SubmitMusicGenerationJob::class);

        $generation = MusicGeneration::query()->firstOrFail();

        // Queued, not called: the request must not wait minutes for audio.
        $this->assertSame(MusicGenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->execution_mode);

        // And when a worker runs it, it finishes on the spot.
        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        $this->assertSame(MusicGenerationStatus::Completed, $generation->refresh()->status);
    }

    // --------------------------------------------------------------- helpers

    private function submitter(): SubmitMusicGeneration
    {
        return $this->app->make(SubmitMusicGeneration::class);
    }

    private function start(): MusicGeneration
    {
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(StartMusicGeneration::class)->handle(prompt: 'a quiet ballad');
    }

    private function useProvider(GenerationProvider $provider): void
    {
        config([
            'generation.default' => $provider->name(),
            'generation.preference' => [$provider->name()],
            'generation.providers.'.$provider->name() => ['driver' => 'fake'],
        ]);

        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->forgetInstance(SelectGenerationProvider::class);
        $this->app->make(MusicGenerationManager::class)->register($provider->name(), $provider);
    }
}

/**
 * A provider that answers with the audio — the shape ACE-Step's own reference
 * server has, and the reason GEN-007 exists.
 */
final class AtOnceProvider implements SynchronousMusicGenerationProvider
{
    public int $calls = 0;

    public function name(): string
    {
        return 'at-once';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generate(GenerationRequest $request): GenerationExecution
    {
        $this->calls++;

        return GenerationExecution::completed(
            [new ProviderResult(providerResultId: 'take-one', sourceReference: 'memory://take-one')],
            model: 'at-once-v1',
        );
    }
}

/**
 * A supplier that genuinely offers both shapes.
 */
final class EitherWayProvider implements MusicGenerationProvider, SynchronousMusicGenerationProvider
{
    public function name(): string
    {
        return 'either-way';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generate(GenerationRequest $request): GenerationExecution
    {
        return GenerationExecution::completed(
            [new ProviderResult(providerResultId: 'take-one', sourceReference: 'memory://take-one')],
        );
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult(
            'either-way',
            'job-1',
            GenerationStatus::Pending,
        );
    }

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return $this->createGeneration(new GenerationRequest(prompt: ''));
    }

    public function fetchResults(string $providerJobId): array
    {
        return [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        return false;
    }
}

/**
 * An asynchronous provider that returns no job identifier — a fault, and not to
 * be confused with a synchronous answer.
 */
final class SilentAboutItsJobProvider implements MusicGenerationProvider
{
    public function name(): string
    {
        return 'silent-about-its-job';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult(
            'silent-about-its-job',
            '',
            GenerationStatus::Pending,
        );
    }

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return $this->createGeneration(new GenerationRequest(prompt: ''));
    }

    public function fetchResults(string $providerJobId): array
    {
        return [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        return false;
    }
}
