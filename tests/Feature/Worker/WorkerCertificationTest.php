<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Worker\Certification\CertifyWorker;
use SaniTube\Worker\Certification\WorkerCheck;
use SaniTube\Worker\Certification\WorkerCheckOutcome;
use Tests\TestCase;

/**
 * WRK-003. A command an operator can run against a real worker.
 *
 * STO-006 gave object storage `--certify`: something you run against the real
 * service, which says what it proved and what no command can prove. The worker
 * had no equivalent. Its handshake, refusals and containment are tested against
 * a faked transport — the right way to test them, and it says nothing about the
 * machine at the end of a URL somebody typed into `.env`.
 *
 * **This does not certify anything.** The ledger row stays BLOCKED_EXTERNAL
 * until somebody runs it against a running worker. What changes is that they
 * can, and that a failure is named rather than discovered when an import
 * quietly stops finishing.
 *
 * These tests fake the transport, which is exactly the limitation the command
 * exists to escape — so what they hold is the *reading*: given a worker that
 * behaves this way, does the report say so. Whether a real GPU box behaves any
 * of these ways is what running it answers.
 */
final class WorkerCertificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['worker.url' => 'http://worker.test', 'worker.token' => 'a-worker-token']);
    }

    #[Test]
    public function an_installation_with_no_worker_is_skipped_rather_than_failed(): void
    {
        config(['worker.url' => '', 'worker.token' => '']);

        $checks = $this->certify();

        $this->assertSame(WorkerCheckOutcome::Skipped, $this->check($checks, 'configured')->outcome);
    }

    #[Test]
    public function a_worker_that_does_not_answer_is_a_named_failure(): void
    {
        Http::fake(['worker.test/*' => Http::response('', 500)]);

        $handshake = $this->check($this->certify(), 'handshake');

        $this->assertSame(WorkerCheckOutcome::Failed, $handshake->outcome);
        $this->assertStringContainsString('handshake', (string) $handshake->detail);
    }

    #[Test]
    public function a_worker_that_answers_is_named_by_what_it_calls_itself(): void
    {
        $this->workerAnswering(['MEDIA_PROBE', 'AUDIO_FINGERPRINT']);

        $handshake = $this->check($this->certify(), 'handshake');

        $this->assertSame(WorkerCheckOutcome::Passed, $handshake->outcome);
        $this->assertStringContainsString('gpu-one', (string) $handshake->detail);
        $this->assertStringContainsString('2.1.0', (string) $handshake->detail);
    }

    #[Test]
    public function what_it_was_built_with_and_cannot_run_is_reported_and_not_failed(): void
    {
        // A box with the transcription handler installed and no model on disk.
        // A correct deployment, and the most useful line in the report: the gap
        // between what somebody installed and what will actually run.
        $this->workerAnswering(['MEDIA_PROBE'], registered: ['MEDIA_PROBE', 'TRANSCRIPTION']);

        $runnable = $this->check($this->certify(), 'capabilities_runnable');

        $this->assertSame(WorkerCheckOutcome::Passed, $runnable->outcome);
        $this->assertStringContainsString('TRANSCRIPTION', (string) $runnable->detail);
    }

    #[Test]
    public function a_worker_that_refuses_work_it_does_not_announce_passes(): void
    {
        $this->workerAnswering(['MEDIA_PROBE'], jobStatus: 404);

        $refusal = $this->check($this->certify(), 'refuses_unknown_work');

        $this->assertSame(WorkerCheckOutcome::Passed, $refusal->outcome);
    }

    #[Test]
    public function a_worker_that_accepts_anything_it_is_sent_fails(): void
    {
        // The containment claim, asked of the real thing. A worker that answers
        // an unregistered capability is one whose capability list describes an
        // intention rather than a boundary.
        $this->workerAnswering(['MEDIA_PROBE'], jobStatus: 200);

        $refusal = $this->check($this->certify(), 'refuses_unknown_work');

        $this->assertSame(WorkerCheckOutcome::Failed, $refusal->outcome);
        $this->assertStringContainsString('accepted', (string) $refusal->detail);
    }

    #[Test]
    public function a_worker_that_answers_without_the_right_token_fails(): void
    {
        // The one check that would be meaningless against a fake transport if
        // the fake did not distinguish tokens. This one does.
        Http::fake(['worker.test/*' => Http::response($this->identityBody(['MEDIA_PROBE']), 200)]);

        $token = $this->check($this->certify(), 'token_enforced');

        $this->assertSame(WorkerCheckOutcome::Failed, $token->outcome);
        $this->assertStringContainsString('wrong token', (string) $token->detail);
    }

    #[Test]
    public function a_worker_that_checks_its_token_passes(): void
    {
        $this->workerAnswering(['MEDIA_PROBE']);

        $this->assertSame(WorkerCheckOutcome::Passed, $this->check($this->certify(), 'token_enforced')->outcome);
    }

    /**
     * Nothing foreign reaches the report at all, and that is the stronger claim.
     *
     * `WorkerClient` deliberately never carries a client exception's message —
     * its own comment says a client exception quotes the request, and the
     * request carries the worker's address and its token header. So on today's
     * paths there is nothing to redact, and this asserts the absence rather
     * than a redactor doing its job.
     *
     * The redaction is still there, one layer down, for the caller that has not
     * been written yet. `a_detail_is_stripped_of_the_token_and_the_address`
     * holds that where it lives, because a mutation removing it changes nothing
     * a test at this level can see.
     */
    #[Test]
    public function neither_the_token_nor_the_address_reaches_the_report(): void
    {
        Http::fake([
            'worker.test/*' => fn () => throw new \RuntimeException(
                'connect to http://worker.test failed, token a-worker-token rejected',
            ),
        ]);

        $printed = json_encode(array_map(
            static fn (WorkerCheck $check): array => $check->toArray(),
            $this->certify(),
        ));

        $this->assertIsString($printed);
        $this->assertStringNotContainsString('a-worker-token', $printed);
        $this->assertStringNotContainsString('http://worker.test', $printed);
    }

    /**
     * And the layer below refuses both, whatever a caller hands it.
     *
     * Asserted here rather than through the command, because every path that
     * reaches it today passes text this platform wrote. A guard nothing
     * currently exercises is still the guard the next caller inherits, and one
     * no test can kill is one that quietly stops being true.
     */
    #[Test]
    public function a_detail_is_stripped_of_the_token_and_the_address(): void
    {
        $check = WorkerCheck::failed(
            'handshake',
            'POST https://gpu.internal.example/api/worker/jobs failed for token a-worker-token',
            'a-worker-token',
        );

        $detail = (string) $check->detail;

        $this->assertStringNotContainsString('a-worker-token', $detail);
        $this->assertStringNotContainsString('gpu.internal.example', $detail);
        $this->assertStringContainsString('failed', $detail, 'Scrubbed to nothing says less than an empty cell.');
    }

    #[Test]
    public function the_command_exits_non_zero_when_something_failed(): void
    {
        $this->workerAnswering(['MEDIA_PROBE'], jobStatus: 200);

        $this->artisan('sanitube:worker:check')->assertFailed();
    }

    #[Test]
    public function the_command_says_what_it_did_not_prove(): void
    {
        $this->workerAnswering(['MEDIA_PROBE'], jobStatus: 404);

        // A report that listed only what passed would read as "the worker
        // works". It proves the protocol and never asks for real work.
        $this->artisan('sanitube:worker:check')
            ->expectsOutputToContain('Not covered')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- fixtures

    /**
     * A worker that answers the handshake only when the token is right.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>|null  $registered
     */
    private function workerAnswering(array $capabilities, ?array $registered = null, int $jobStatus = 404): void
    {
        $body = $this->identityBody($capabilities, $registered);

        Http::fake([
            'worker.test/api/worker' => function ($request) use ($body) {
                return $request->header('X-SaniTube-Worker-Token') === ['a-worker-token']
                    ? Http::response($body, 200)
                    : Http::response('', 401);
            },
            'worker.test/*' => Http::response($jobStatus === 200 ? ['ok' => true] : '', $jobStatus),
        ]);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>|null  $registered
     * @return array<string, mixed>
     */
    private function identityBody(array $capabilities, ?array $registered = null): array
    {
        return [
            'name' => 'gpu-one',
            'version' => '2.1.0',
            'capabilities' => $capabilities,
            'registered' => $registered ?? $capabilities,
            'tools' => ['ffmpeg' => '6.1'],
        ];
    }

    /**
     * @return list<WorkerCheck>
     */
    private function certify(): array
    {
        return $this->app->make(CertifyWorker::class)->handle();
    }

    /**
     * @param  list<WorkerCheck>  $checks
     */
    private function check(array $checks, string $name): WorkerCheck
    {
        foreach ($checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        $this->fail(sprintf('No check named [%s]. Got: %s.', $name, implode(', ', array_map(
            static fn (WorkerCheck $c): string => $c->name,
            $checks,
        ))));
    }
}
