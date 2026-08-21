<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Worker\Client\WorkerClient;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerIdentity;
use SaniTube\Worker\WorkerJobFailed;
use SaniTube\Worker\WorkerProtocol;
use Tests\TestCase;

/**
 * Core and its worker agree on the wire, or nothing crosses it.
 *
 * DEP-015. The protocol version names the shape of the conversation — the
 * handshake, the job envelope, the refusal codes — separately from the
 * application version, because Core and worker do not deploy in lockstep.
 * The rule these tests hold: a *known* mismatch refuses jobs rather than
 * reinterpreting them, because a payload read under the wrong protocol does
 * not fail — it does the wrong thing quietly, to a master, on a GPU.
 */
final class WorkerProtocolTest extends TestCase
{
    // ----------------------------------------------------------- identity

    #[Test]
    public function this_build_announces_its_protocol_in_the_handshake(): void
    {
        config(['worker.token' => 'a-worker-token', 'worker.identity' => 'test-worker']);

        $this->getJson('/api/worker', ['X-SaniTube-Worker-Token' => 'a-worker-token'])
            ->assertOk()
            ->assertJsonPath('protocol', WorkerProtocol::VERSION);
    }

    #[Test]
    public function a_worker_that_announces_nothing_spoke_version_one_by_definition(): void
    {
        // Workers built before the constant existed sent no protocol field —
        // and spoke exactly the wire now named version 1. Absent is 1, never
        // unknown: treating them as strangers would break every worker that
        // was working yesterday.
        $identity = WorkerIdentity::fromArray(['name' => 'older-worker', 'capabilities' => []]);

        $this->assertNotNull($identity);
        $this->assertSame(1, $identity->protocol);
        $this->assertTrue($identity->speaksOurProtocol());
    }

    #[Test]
    public function the_announced_protocol_round_trips(): void
    {
        $identity = WorkerIdentity::fromArray(['name' => 'w', 'capabilities' => [], 'protocol' => 7]);

        $this->assertNotNull($identity);
        $this->assertSame(7, $identity->protocol);
        $this->assertFalse($identity->speaksOurProtocol());
        $this->assertSame(7, $identity->toArray()['protocol']);
    }

    // --------------------------------------------------------------- jobs

    #[Test]
    public function a_known_mismatch_refuses_the_job_before_anything_is_sent(): void
    {
        config(['worker.url' => 'http://worker.test', 'worker.token' => 'a-token']);

        Http::fake([
            'worker.test/api/worker' => Http::response([
                'name' => 'future-worker',
                'capabilities' => ['music_generation'],
                'protocol' => WorkerProtocol::VERSION + 1,
            ], 200),
            // If the client posts a job anyway, this makes it "succeed" — so
            // the assertion below only holds if the refusal came first.
            'worker.test/*' => Http::response(['ok' => true], 200),
        ]);

        try {
            $this->client()->run(WorkerCapability::MusicGeneration, ['reference' => 'abc']);
            $this->fail('A job crossed a known protocol mismatch.');
        } catch (WorkerJobFailed $failure) {
            $this->assertSame('WORKER_PROTOCOL_MISMATCH', $failure->reason);
        }

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/api/worker/jobs/'));
    }

    #[Test]
    public function a_matching_protocol_lets_the_job_through(): void
    {
        config(['worker.url' => 'http://worker.test', 'worker.token' => 'a-token']);

        Http::fake([
            'worker.test/api/worker' => Http::response([
                'name' => 'current-worker',
                'capabilities' => ['music_generation'],
                'protocol' => WorkerProtocol::VERSION,
            ], 200),
            'worker.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertSame(['ok' => true], $this->client()->run(WorkerCapability::MusicGeneration, ['reference' => 'abc']));
    }

    // ------------------------------------------------------ certification

    #[Test]
    public function certification_names_the_mismatch_with_both_numbers(): void
    {
        config(['worker.url' => 'http://worker.test', 'worker.token' => 'a-token']);

        Http::fake([
            'worker.test/api/worker' => Http::response([
                'name' => 'future-worker',
                'capabilities' => [],
                'protocol' => WorkerProtocol::VERSION + 1,
            ], 200),
            'worker.test/*' => Http::response(['code' => 'WORKER_UNKNOWN_CAPABILITY'], 404),
        ]);

        $this->artisan('sanitube:worker:check')
            ->expectsOutputToContain('update whichever side is behind')
            ->assertFailed();
    }

    // -------------------------------------------------------------- token

    #[Test]
    public function the_token_command_prints_a_fresh_token_and_stores_nothing(): void
    {
        $this->artisan('sanitube:worker:token')
            ->expectsOutputToContain('SANITUBE_WORKER_TOKEN=')
            ->expectsOutputToContain('BOTH hosts')
            ->assertSuccessful();

        // It writes no file: the environment is the store, and this command
        // remembering the token would be a second copy nobody audits.
        $this->assertFileDoesNotExist(storage_path('worker-token'));
        $this->assertFileDoesNotExist(base_path('.env.worker'));
    }

    // ----------------------------------------------------------- fixtures

    private function client(): WorkerClient
    {
        $this->app->forgetInstance(WorkerClient::class);

        return $this->app->make(WorkerClient::class);
    }
}
