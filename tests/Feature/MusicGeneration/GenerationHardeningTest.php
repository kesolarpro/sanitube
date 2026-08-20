<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\ProviderFailure;
use SaniTube\MusicGeneration\ProviderResult;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;
use Throwable;

/**
 * GEN-003 — generation hardening.
 *
 * Two claims, both about things that cost something real.
 *
 * **A failure reason never carries what the provider said about the request.**
 * It is rendered on the Studio screen and returned by the API, and before this
 * ticket it was whatever the HTTP client's exception message happened to be —
 * which quotes the request URI, and on a provider authenticated by a
 * query-string token *is* the credential.
 *
 * **A generation is submitted once, even when two workers are handed it.**
 * `provider_job_id` cannot prevent that on its own: for the whole duration of
 * the call it guards, the column it guards on is still null.
 */
final class GenerationHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real provider URL with a real-looking bearer token in the query string.
     * Not a secret — it is a literal invented here — but shaped exactly like
     * the thing that must never be rendered.
     */
    private const LEAKY_URL = 'https://api.example-provider.test/v1/generate?api_key=sk-live-9f2a7c41b8e6';

    private FakeMusicGenerationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);

        $this->provider = new FakeMusicGenerationProvider;

        config(['generation.default' => 'fake']);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', $this->provider);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        Queue::fake();
    }

    // ------------------------------------------- what a failure may say

    #[Test]
    public function a_transport_exception_never_reaches_the_failure_reason(): void
    {
        // Guzzle's connection exception quotes the request it could not make.
        // This is the leak the ticket exists to close.
        $generation = $this->submitThrowing(new ConnectionException(
            'cURL error 28: Operation timed out after 30000 milliseconds for '.self::LEAKY_URL,
        ));

        $reason = (string) $generation->failure_reason;

        $this->assertStringNotContainsString('sk-live-9f2a7c41b8e6', $reason);
        $this->assertStringNotContainsString('api.example-provider.test', $reason);
        $this->assertStringNotContainsString('cURL', $reason);
        $this->assertSame('The [fake] provider could not be reached.', $reason);
        $this->assertSame(MusicGenerationStatus::Failed, $generation->status);
    }

    #[Test]
    public function a_rejected_request_keeps_its_status_code_and_nothing_else(): void
    {
        // The status is the part an operator can act on: 401 is a key, 429 is
        // a rate limit, 5xx is theirs. The body is the part that quotes the
        // prompt back, and the prompt is catalogue data.
        $generation = $this->submitThrowing($this->requestException(401, '{"error":"bad key for '.self::LEAKY_URL.'"}'));

        $reason = (string) $generation->failure_reason;

        $this->assertSame('The [fake] provider answered 401.', $reason);
        $this->assertStringNotContainsString('sk-live', $reason);
    }

    #[Test]
    public function the_platforms_own_exception_is_carried_word_for_word(): void
    {
        // The distinction the whole class rests on. We wrote this sentence; it
        // says only what we chose to say, so nothing is gained by mangling it.
        $ours = GenerationException::projectClosed('a-uuid');
        $generation = $this->submitThrowing($ours);

        $this->assertSame($ours->getMessage(), $generation->failure_reason);
    }

    #[Test]
    public function a_vendor_explanation_is_kept_attributed_and_stripped_of_addresses(): void
    {
        $generation = $this->submitted();

        $this->provider->fail(
            (string) $generation->provider_job_id,
            "Rejected by our content policy.\nSee ".self::LEAKY_URL.' for details.',
        );

        $generation = $this->poller()->handle($generation);
        $reason = (string) $generation->failure_reason;

        // The useful half survives...
        $this->assertStringContainsString('Rejected by our content policy.', $reason);
        // ...attributed, because a vendor reporting what it did and the
        // platform making a decision are not the same claim.
        $this->assertStringStartsWith('The [fake] provider reported:', $reason);
        // ...and the address does not, whether or not it holds a credential a
        // redactor could recognise.
        $this->assertStringNotContainsString('sk-live-9f2a7c41b8e6', $reason);
        $this->assertStringNotContainsString('https://', $reason);
        $this->assertStringContainsString(CredentialRedactor::MASK, $reason);
        // One line: a stack trace in a column is both a leak and unreadable.
        $this->assertStringNotContainsString("\n", $reason);
    }

    #[Test]
    public function a_vendor_that_explains_nothing_gets_the_platforms_sentence(): void
    {
        foreach ([null, '', '   ', "\n\n"] as $nothing) {
            $this->assertSame(
                'fallback sentence',
                ProviderFailure::fromProvider('fake', $nothing, 'fallback sentence'),
            );
        }

        // And a message that is *entirely* an address leaves nothing to
        // attribute, so it must not become "the provider reported: [redacted]".
        $this->assertSame(
            'fallback sentence',
            ProviderFailure::fromProvider('fake', self::LEAKY_URL, 'fallback sentence'),
        );
    }

    #[Test]
    public function a_failure_reason_is_bounded(): void
    {
        $reason = ProviderFailure::fromProvider('fake', str_repeat('a', 5000), 'unused');

        $this->assertLessThanOrEqual(
            ProviderFailure::LIMIT + strlen('The [fake] provider reported: ') + 4,
            strlen($reason),
        );
        $this->assertStringEndsWith('…', $reason);
    }

    #[Test]
    public function a_reason_stored_before_this_ticket_is_sanitised_where_it_is_read(): void
    {
        // A migration cannot clean these: the damage, if any, is already in the
        // column. So the read boundary refuses regardless of how it got there.
        $this->assertNotNull(ProviderFailure::forDisplay('boom'));
        $this->assertStringNotContainsString(
            'sk-live-9f2a7c41b8e6',
            (string) ProviderFailure::forDisplay('cURL error for '.self::LEAKY_URL),
        );
        $this->assertNull(ProviderFailure::forDisplay(null));
    }

    #[Test]
    public function the_studio_screen_renders_the_sanitised_reason(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the generation detail screen. The
        // service being safe is not the claim -- the browser is.
        $generation = $this->submitted();

        // Written past the service, exactly as a row from before this ticket
        // would have been.
        $generation->forceFill([
            'status' => MusicGenerationStatus::Failed,
            'failure_reason' => 'cURL error 7: could not connect to '.self::LEAKY_URL,
        ])->save();

        $body = $this->actingAs($this->owner())
            ->get('/studio/generations/'.$generation->uuid)
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('sk-live-9f2a7c41b8e6', $body);
        $this->assertStringNotContainsString('api.example-provider.test', $body);
    }

    #[Test]
    public function the_api_returns_the_sanitised_reason(): void
    {
        // WHO ELSE CALLS THIS IN PRODUCTION: the internal API. Two read
        // boundaries render this column, and one of them being safe is not the
        // claim -- an integration reading v1 must not be handed the address
        // either.
        config(['sanitube.api.internal_token' => 'generation-token']);

        $generation = $this->submitted();
        $generation->forceFill([
            'status' => MusicGenerationStatus::Failed,
            'failure_reason' => 'cURL error 7: could not connect to '.self::LEAKY_URL,
        ])->save();

        $body = $this->withHeader('X-SaniTube-Api-Token', 'generation-token')
            ->getJson('api/v1/generations/'.$generation->uuid)
            ->assertOk()
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('sk-live-9f2a7c41b8e6', $body);
        $this->assertStringNotContainsString('api.example-provider.test', $body);
    }

    // ------------------------------------------------- submitted once

    #[Test]
    public function two_workers_handed_the_same_generation_call_the_provider_once(): void
    {
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        // Two workers, two independently loaded instances of the same row —
        // which is precisely the state that made the old read-then-write check
        // pass twice. Both see provider_job_id null, because it *is* null.
        $first = MusicGeneration::query()->findOrFail($generation->id);
        $second = MusicGeneration::query()->findOrFail($generation->id);

        $this->assertNull($first->provider_job_id);
        $this->assertNull($second->provider_job_id);

        $this->submitter()->handle($first);
        $this->submitter()->handle($second);

        // The fake counts every createGeneration call by issuing a new job id.
        // Two calls would have produced fake-002.
        $this->assertSame('fake-001', MusicGeneration::query()->findOrFail($generation->id)->provider_job_id);
        $this->assertSame(1, $this->provider->generationsCreated());
    }

    #[Test]
    public function a_live_claim_is_left_alone(): void
    {
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        $generation->forceFill(['submission_claimed_at' => Carbon::now()])->save();

        $this->submitter()->handle(MusicGeneration::query()->findOrFail($generation->id));

        $this->assertSame(0, $this->provider->generationsCreated());
        $this->assertNull(MusicGeneration::query()->findOrFail($generation->id)->provider_job_id);
        // Not failed. Somebody else is doing the work; a lost race is a
        // success, not an error.
        $this->assertSame(MusicGenerationStatus::Queued, MusicGeneration::query()->findOrFail($generation->id)->status);
    }

    #[Test]
    public function an_abandoned_claim_expires_so_a_crash_does_not_stall_a_generation(): void
    {
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        // The worker that claimed this died between claiming and answering.
        $generation->forceFill([
            'submission_claimed_at' => Carbon::now()->subSeconds((int) config('generation.submission_claim_seconds') + 1),
        ])->save();

        $this->submitter()->handle(MusicGeneration::query()->findOrFail($generation->id));

        $this->assertSame(1, $this->provider->generationsCreated());
        $this->assertNotNull(MusicGeneration::query()->findOrFail($generation->id)->provider_job_id);
    }

    #[Test]
    public function the_claim_is_released_once_the_submission_settles(): void
    {
        // Held only for the call. A submitted row that still looks claimed
        // would read as busy for the rest of the expiry window.
        $submitted = $this->submitted();
        $this->assertNull($submitted->submission_claimed_at);

        $failed = $this->submitThrowing(new ConnectionException('down'));
        $this->assertNull($failed->submission_claimed_at);
    }

    #[Test]
    public function the_submission_job_does_not_retry(): void
    {
        // WHO CALLS THIS IN PRODUCTION: SubmitMusicGenerationJob, dispatched
        // by StartMusicGeneration's caller. A retry cannot tell a refusal from
        // a timeout on a request the provider actually received.
        $generation = $this->starter()->handle(prompt: 'ambient piano');
        $job = new SubmitMusicGenerationJob($generation->uuid);

        $this->assertSame(1, $job->tries);

        $job->handle($this->submitter());
        $job->handle($this->submitter());

        $this->assertSame(1, $this->provider->generationsCreated());
    }

    // ------------------------------------------------------ the poll bound

    #[Test]
    public function every_poll_counts_towards_the_bound_even_when_two_run_at_once(): void
    {
        $generation = $this->submitted();

        // Two pollers holding stale instances: the read-then-write increment
        // had both write 1, so the bound that stops a runaway loop advanced by
        // one per round rather than per poll.
        $first = MusicGeneration::query()->findOrFail($generation->id);
        $second = MusicGeneration::query()->findOrFail($generation->id);

        $this->poller()->handle($first);
        $this->poller()->handle($second);

        $this->assertSame(2, MusicGeneration::query()->findOrFail($generation->id)->poll_count);
    }

    #[Test]
    public function a_generation_that_is_never_answered_is_failed_rather_than_left_in_flight(): void
    {
        config(['generation.poll.max_polls' => 2]);

        $generation = $this->submitted();

        for ($i = 0; $i < 4; $i++) {
            $generation = $this->poller()->handle($generation);
        }

        $this->assertSame(MusicGenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('2 polls', (string) $generation->failure_reason);
    }

    // ----------------------------------------------------------- fixtures

    private function submitted(): MusicGeneration
    {
        return $this->submitter()->handle($this->starter()->handle(prompt: 'ambient piano'));
    }

    /**
     * Submit against a provider that throws the given exception.
     */
    private function submitThrowing(Throwable $exception): MusicGeneration
    {
        $throwing = new class($exception) implements MusicGenerationProvider
        {
            public function __construct(private readonly Throwable $exception) {}

            public function name(): string
            {
                return 'fake';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function createGeneration(GenerationRequest $request): GenerationResult
            {
                throw $this->exception;
            }

            public function getGenerationStatus(string $providerJobId): GenerationResult
            {
                throw $this->exception;
            }

            /**
             * @return list<ProviderResult>
             */
            public function fetchResults(string $providerJobId): array
            {
                return [];
            }

            public function cancelGeneration(string $providerJobId): bool
            {
                return false;
            }
        };

        $this->app->forgetInstance(MusicGenerationManager::class);
        $manager = $this->app->make(MusicGenerationManager::class);
        $manager->register('fake', $throwing);

        $generation = $this->starter()->handle(prompt: 'ambient piano');

        return $this->app->make(SubmitMusicGeneration::class)->handle($generation);
    }

    private function requestException(int $status, string $body): RequestException
    {
        $psr = new \GuzzleHttp\Psr7\Response($status, [], $body);

        $this->assertInstanceOf(ResponseInterface::class, $psr);

        return new RequestException(new Response($psr));
    }

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

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
