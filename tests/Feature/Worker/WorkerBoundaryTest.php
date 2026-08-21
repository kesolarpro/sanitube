<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Worker\Client\WorkerClient;
use SaniTube\Worker\Contracts\WorkerJobHandler;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerIdentity;
use SaniTube\Worker\WorkerJobFailed;
use SaniTube\Worker\WorkerRegistry;
use Tests\TestCase;

/**
 * WRK-001 — one boundary, addressed by capability.
 *
 * GEN-008 built a remote boundary for one purpose: running ACE-Step on a host
 * with a GPU. The temptation immediately afterwards is to build a second for
 * FFmpeg, a third for Chromaprint, a fourth for transcription — four
 * authentication schemes, four failure vocabularies, four things to deploy and
 * four places for one bug.
 *
 * So there is **one** boundary and two routes: a handshake and a job. A
 * capability added later needs a handler and a registration, and changes
 * neither the protocol, the middleware, nor Core's client.
 *
 * The two claims worth testing are both negative: **an unconfigured
 * installation advertises nothing at all**, and **a capability a worker cannot
 * currently carry out is not silently accepted.**
 */
final class WorkerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['worker.token' => 'a-worker-token', 'worker.identity' => 'gpu-one']);
    }

    // ------------------------------------------------------------- existence

    #[Test]
    public function an_installation_that_is_not_a_worker_advertises_nothing(): void
    {
        // Almost every SaniTube is Core only. None of them should say a worker
        // exists here, still less expose one that fell back to something
        // permissive.
        config(['worker.token' => '']);

        $this->getJson('/api/worker')->assertNotFound();
        $this->postJson('/api/worker/jobs/music-generation', [])->assertNotFound();
    }

    #[Test]
    public function a_wrong_token_is_refused_and_a_right_one_is_not(): void
    {
        $this->getJson('/api/worker', ['X-SaniTube-Worker-Token' => 'wrong'])->assertUnauthorized();
        $this->getJson('/api/worker', ['X-SaniTube-Worker-Token' => 'a-worker-token'])->assertOk();
    }

    #[Test]
    public function the_worker_token_is_not_the_catalogue_api_token(): void
    {
        // Different powers. One reads a catalogue; this one spends GPU time,
        // runs binaries and writes into shared storage. A single token would
        // make it impossible to grant one without the other.
        config(['sanitube.api.internal_token' => 'the-catalogue-token']);

        $this->getJson('/api/worker', ['X-SaniTube-Worker-Token' => 'the-catalogue-token'])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------- handshake

    #[Test]
    public function the_handshake_says_who_this_worker_is_and_what_it_can_do(): void
    {
        $this->registerHandler(new StubHandler(WorkerCapability::Waveform, runnable: true));

        $this->getJson('/api/worker', $this->auth())
            ->assertOk()
            ->assertJsonPath('name', 'gpu-one')
            ->assertJsonStructure(['name', 'version', 'capabilities', 'registered', 'tools'])
            // Contained, not equal. Other modules register their own handlers
            // and this test is about the handshake's shape, not about which
            // capabilities a full application happens to carry.
            ->assertJsonFragment(['capabilities' => $this->capabilitiesIncluding('WAVEFORM')]);
    }

    #[Test]
    public function a_capability_that_is_known_but_not_runnable_is_reported_apart(): void
    {
        // The distinction an operator acts on: "this worker does not do that"
        // and "this worker does that but cannot right now" lead to different
        // afternoons.
        $this->registerHandler(new StubHandler(WorkerCapability::Waveform, runnable: false));

        $response = $this->getJson('/api/worker', $this->auth())->assertOk();

        $this->assertNotContains('WAVEFORM', $response->json('capabilities'));
        $this->assertContains('WAVEFORM', $response->json('registered'));
    }

    #[Test]
    public function an_unnamed_worker_says_so_rather_than_inventing_a_hostname(): void
    {
        // Configured, never derived. A hostname changes when a machine is
        // rebuilt, and an audit line naming which worker did something has to
        // outlive that.
        config(['worker.identity' => '']);

        $this->getJson('/api/worker', $this->auth())
            ->assertOk()
            ->assertJsonPath('name', 'unnamed-worker');
    }

    // ------------------------------------------------------------------ jobs

    #[Test]
    public function a_capability_this_build_has_never_heard_of_is_a_404(): void
    {
        $this->postJson('/api/worker/jobs/telepathy', [], $this->auth())->assertNotFound();
    }

    #[Test]
    public function a_capability_with_no_handler_is_refused_without_naming_the_ones_that_have_one(): void
    {
        $this->postJson('/api/worker/jobs/stem-separation', [], $this->auth())
            ->assertNotFound()
            ->assertJsonPath('code', 'CAPABILITY_NOT_REGISTERED');
    }

    #[Test]
    public function a_registered_capability_that_cannot_run_says_try_later_rather_than_never(): void
    {
        // 503, not 404. The difference is what tells Core to come back.
        $this->registerHandler(new StubHandler(WorkerCapability::Waveform, runnable: false));

        $this->postJson('/api/worker/jobs/waveform', ['subject' => 'x'], $this->auth())
            ->assertStatus(503)
            ->assertJsonPath('code', 'CAPABILITY_NOT_RUNNABLE');
    }

    #[Test]
    public function the_handler_declares_the_rules_and_the_controller_applies_them(): void
    {
        // The security boundary stays beside the code it protects. A generic
        // controller that carried its own field list would be a second place
        // for every capability's rules to live.
        $this->registerHandler(new StubHandler(WorkerCapability::Waveform, runnable: true));

        $this->postJson('/api/worker/jobs/waveform', [], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PAYLOAD');
    }

    #[Test]
    public function a_field_the_handler_did_not_declare_never_reaches_it(): void
    {
        // What makes `rules()` a boundary rather than a convenience: the
        // handler is handed the validated payload, so an undeclared field is
        // one a caller cannot set.
        $handler = new StubHandler(WorkerCapability::Waveform, runnable: true);
        $this->registerHandler($handler);

        $this->postJson('/api/worker/jobs/waveform', [
            'subject' => 'x',
            'checkpoint_path' => '/etc/shadow',
        ], $this->auth())->assertOk();

        $this->assertSame(['subject' => 'x'], $handler->received);
    }

    #[Test]
    public function a_refusal_carries_a_code_and_not_a_stack(): void
    {
        $this->registerHandler(new StubHandler(WorkerCapability::Waveform, runnable: true, failWith: 'NO_ROOM_ON_DISK'));

        $this->postJson('/api/worker/jobs/waveform', ['subject' => 'x'], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_ROOM_ON_DISK');
    }

    // ---------------------------------------------------------- Core's client

    #[Test]
    public function core_with_no_worker_configured_asks_nobody_anything(): void
    {
        config(['worker.url' => '', 'worker.token' => '']);

        $client = $this->client();

        $this->assertFalse($client->isConfigured());
        $this->assertNull($client->identity());
        $this->assertFalse($client->can(WorkerCapability::MusicGeneration));

        try {
            $client->run(WorkerCapability::MusicGeneration, []);
            $this->fail('An unconfigured worker must refuse.');
        } catch (WorkerJobFailed $failure) {
            $this->assertSame('WORKER_NOT_CONFIGURED', $failure->reason);
        }
    }

    #[Test]
    public function a_worker_answering_in_a_shape_core_does_not_understand_is_a_refusal_not_a_crash(): void
    {
        // A proxy that returns a login page, a load balancer that answers with
        // a string, a worker mid-upgrade. Each is a 200 carrying something that
        // is not a result, and without this check the declared return type
        // turns it into a TypeError inside a queue worker rather than a
        // generation that failed with a reason.
        config(['worker.url' => 'http://worker.test']);

        Http::fake(['worker.test/*' => Http::response('"just a string"', 200, ['Content-Type' => 'application/json'])]);

        try {
            $this->client()->run(WorkerCapability::MusicGeneration, ['reference' => 'abc']);
            $this->fail('A malformed answer must refuse.');
        } catch (WorkerJobFailed $failure) {
            $this->assertSame('WORKER_MALFORMED_RESPONSE', $failure->reason);
        }
    }

    #[Test]
    public function an_unreachable_worker_is_a_reason_and_never_the_clients_own_words(): void
    {
        // A client exception quotes the request that produced it, which carries
        // the worker's address and its token header.
        config(['worker.url' => 'http://worker.test']);

        Http::fake(['worker.test/*' => fn () => throw new \RuntimeException('connect() to 10.0.0.5 failed, token abc')]);

        try {
            $this->client()->run(WorkerCapability::MusicGeneration, ['reference' => 'abc']);
            $this->fail('An unreachable worker must refuse.');
        } catch (WorkerJobFailed $failure) {
            $this->assertSame('WORKER_UNREACHABLE', $failure->reason);
            $this->assertStringNotContainsString('10.0.0.5', $failure->getMessage());
            $this->assertStringNotContainsString('abc', $failure->getMessage());
        }
    }

    #[Test]
    public function a_newer_worker_talking_to_an_older_core_is_usable_for_what_they_share(): void
    {
        // A capability Core has never heard of is dropped rather than making
        // the whole handshake unreadable.
        $identity = WorkerIdentity::fromArray([
            'name' => 'gpu-one',
            'version' => '9.9',
            'capabilities' => ['MUSIC_GENERATION', 'TIME_TRAVEL'],
        ]);

        $this->assertInstanceOf(WorkerIdentity::class, $identity);
        $this->assertSame([WorkerCapability::MusicGeneration], $identity->capabilities);
    }

    #[Test]
    public function a_worker_that_will_not_say_who_it_is_has_not_completed_the_handshake(): void
    {
        // Treated as no worker at all rather than as an anonymous one.
        $this->assertNull(WorkerIdentity::fromArray(['capabilities' => ['MUSIC_GENERATION']]));
        $this->assertNull(WorkerIdentity::fromArray(['name' => '   ']));
    }

    // ------------------------------------------------------------- the shape

    #[Test]
    public function every_capability_has_one_spelling_in_a_url(): void
    {
        // Two spellings of one capability is how a route and a registry stop
        // agreeing, so the slug is derived rather than a second constant.
        foreach (WorkerCapability::cases() as $capability) {
            $this->assertSame($capability, WorkerCapability::fromSlug($capability->slug()));
            $this->assertMatchesRegularExpression('/^[a-z-]+$/', $capability->slug());
        }
    }

    #[Test]
    public function the_boundary_knows_nothing_about_what_it_carries(): void
    {
        // The property that keeps it general. The moment the Worker module
        // names a subsystem, adding the next one means editing it.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('src/Worker')));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // Comments stripped: WorkerCapability names tools in prose to say
            // what each capability *means*, and a guard that could not tell
            // prose from code would chase the explanation out of the file.
            $source = $this->code($file->getPathname());

            // Namespace references, not bare words: `WorkerCapability` legitimately
            // has a `MusicGeneration` *case*, because naming a kind of work is
            // this module's job. Depending on the module that implements it is
            // not.
            foreach ([
                'SaniTube\\MusicGeneration',
                'SaniTube\\Media',
                'SaniTube\\Transcription',
                'AceStep',
            ] as $subsystem) {
                $this->assertStringNotContainsString(
                    $subsystem,
                    $source,
                    sprintf('%s names [%s]; the boundary must stay general.', $file->getFilename(), $subsystem),
                );
            }
        }
    }

    #[Test]
    public function registering_a_handler_does_not_build_it(): void
    {
        // A registry that instantiated every handler at boot would make booting
        // cost whatever the most expensive handler's dependencies cost, on
        // every request -- and would freeze those dependencies while the
        // container was still being assembled. Found by a test that failed for
        // exactly that reason.
        // A counter on an object rather than a captured bool: static analysis
        // narrows a by-reference bool to its initial value and then reports the
        // second assertion as impossible, which would be a true statement about
        // the types and a false one about the test.
        $builds = new \ArrayObject(['count' => 0]);

        $this->app->make(WorkerRegistry::class)->register(
            WorkerCapability::Waveform,
            function () use ($builds): WorkerJobHandler {
                $builds['count']++;

                return new StubHandler(WorkerCapability::Waveform, runnable: true);
            },
        );

        $this->assertSame(0, $builds['count'], 'Registering must not build the handler.');

        $registry = $this->app->make(WorkerRegistry::class);
        $registry->handlerFor(WorkerCapability::Waveform);
        $registry->handlerFor(WorkerCapability::Waveform);

        // Built once, then remembered: a handler resolved per request would put
        // its dependencies' cost on every job.
        $this->assertSame(1, $builds['count']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * A file's source with its comments removed.
     */
    private function code(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function registerHandler(StubHandler $handler): void
    {
        $this->app->make(WorkerRegistry::class)->register(
            $handler->capability(),
            fn (): WorkerJobHandler => $handler,
        );
    }

    private function client(): WorkerClient
    {
        $this->app->forgetInstance(WorkerClient::class);

        return $this->app->make(WorkerClient::class);
    }

    /**
     * The runnable list this application reports, with one name asserted into
     * it — so the test states what it means without pinning what other modules
     * register.
     *
     * @return list<string>
     */
    private function capabilitiesIncluding(string $capability): array
    {
        $reported = $this->getJson('/api/worker', $this->auth())->json('capabilities');

        $this->assertIsArray($reported);
        $this->assertContains($capability, $reported);

        return $reported;
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['X-SaniTube-Worker-Token' => 'a-worker-token'];
    }
}

/**
 * A capability that does nothing, so the boundary can be tested without one of
 * the real ones — which is the point of a boundary that knows nothing about
 * what it carries.
 */
final class StubHandler implements WorkerJobHandler
{
    /** @var array<string, mixed> */
    public array $received = [];

    public function __construct(
        private readonly WorkerCapability $capability,
        private readonly bool $runnable,
        private readonly ?string $failWith = null,
    ) {}

    public function capability(): WorkerCapability
    {
        return $this->capability;
    }

    public function isRunnable(): bool
    {
        return $this->runnable;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['subject' => ['required', 'string', 'max:64']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $this->received = $payload;

        if ($this->failWith !== null) {
            throw WorkerJobFailed::because($this->failWith);
        }

        return ['ok' => true];
    }
}
