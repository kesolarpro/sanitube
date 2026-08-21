<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\MusicGeneration\AceStep\AceStepEngine;
use SaniTube\MusicGeneration\AceStep\ContainedPath;
use SaniTube\MusicGeneration\AceStep\HttpAceStepEngine;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Testing\FakeAceStepEngine;
use SaniTube\MusicGeneration\Worker\RunGenerationOnWorker;
use SaniTube\MusicGeneration\Worker\WorkerGenerationFailed;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * GEN-008 — the generation worker, and the path that never leaves it.
 *
 * ACE-Step's verified contract answers `POST /generate` with `output_path`: a
 * path on **its own** filesystem. SaniTube Core runs on cPanel, on a small VPS,
 * on whatever an operator has. Making Core open that path would mean making
 * Core and a GPU host share a filesystem, and that is a deployment SaniTube
 * must never require.
 *
 * So a worker sits between them, and **the path stops there**. It opens the
 * file, stages the bytes into the storage both sides already share, and tells
 * Core a storage key — something Core knows how to ingest from anywhere.
 *
 * Everything dangerous about this integration is in one sentence — *the engine
 * tells us a path and we open it* — so most of what follows is about refusing
 * to.
 */
final class GenerationWorkerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private InMemoryStorageProvider $store;

    private FakeAceStepEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sanitube-gen-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        config([
            'generation.acestep.output_root' => $this->root,
            'generation.worker.staging_prefix' => 'staging/generation',
            'generation.worker.allowed_extensions' => ['wav', 'flac', 'mp3'],
            'generation.worker.max_output_bytes' => 0,
        ]);

        $this->engine = new FakeAceStepEngine;
        $this->app->instance(AceStepEngine::class, $this->engine);

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

    // ------------------------------------------------------ the ordinary case

    #[Test]
    public function a_rendered_file_is_staged_into_shared_storage_and_the_path_is_forgotten(): void
    {
        $staged = $this->worker()->handle($this->request(), 'abc123');

        // A storage key. Not a path, and nothing that names the worker's disk.
        $this->assertStringStartsWith('staging/generation/', $staged['key']);
        $this->assertStringNotContainsString($this->root, $staged['key']);
        $this->assertTrue($this->store->exists($staged['key']));
        $this->assertGreaterThan(0, $staged['bytes']);
    }

    #[Test]
    public function the_worker_chooses_the_output_path_and_never_the_caller(): void
    {
        // The engine takes `output_path` from its client, so whoever supplies
        // it decides where a file lands. That decision is the worker's.
        $this->worker()->handle($this->request(), 'abc123');

        $asked = $this->engine->requests[0]['output_path'] ?? '';

        $this->assertStringStartsWith($this->root.DIRECTORY_SEPARATOR, (string) $asked);
        $this->assertStringContainsString('abc123', (string) $asked);
    }

    #[Test]
    public function a_reference_that_is_not_a_reference_cannot_shape_a_filename(): void
    {
        // A filename is the one place a caller-supplied string touches a
        // filesystem. Transformed to hex, not escaped and not validated --
        // the only one of the three that a later edit cannot get wrong.
        $this->worker()->handle($this->request(), '../../etc/passwd');

        $asked = (string) ($this->engine->requests[0]['output_path'] ?? '');

        $this->assertStringNotContainsString('..', $asked);
        $this->assertStringNotContainsString('passwd', $asked);
        $this->assertStringStartsWith($this->root.DIRECTORY_SEPARATOR, $asked);
    }

    #[Test]
    public function the_temporary_file_is_gone_afterwards(): void
    {
        $staged = $this->worker()->handle($this->request(), 'abc123');

        // A worker that leaves rendered masters behind fills a GPU host's disk
        // in a week.
        $this->assertSame([], glob($this->root.'/*') ?: []);
        $this->assertTrue($this->store->exists($staged['key']));
    }

    #[Test]
    public function the_temporary_file_is_gone_even_when_staging_fails(): void
    {
        config(['generation.worker.allowed_extensions' => ['flac']]);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('A .wav must be refused when only .flac is allowed.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_UNEXPECTED_TYPE', $exception->reason);
        }

        $this->assertSame([], glob($this->root.'/*') ?: []);
    }

    // --------------------------------------------------- refusing to open it

    #[Test]
    public function an_engine_that_reports_a_path_outside_its_root_is_refused(): void
    {
        // Traversal. The check resolves before it compares, so `..` is gone by
        // the time anything is decided.
        $outside = sys_get_temp_dir().'/sanitube-outside-'.bin2hex(random_bytes(4)).'.wav';
        file_put_contents($outside, 'not ours');

        $this->engine->writingInstead($outside);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('A path outside the root must be refused.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_ESCAPED_ROOT', $exception->reason);
        }

        // And the file it pointed at is untouched: refusing is not deleting
        // somebody else's file.
        $this->assertFileExists($outside);
        @unlink($outside);
    }

    #[Test]
    public function a_symlink_inside_the_root_that_points_out_of_it_is_refused(): void
    {
        // **The case a prefix check on the unresolved string would let
        // through.** The path starts with the root and the file is somewhere
        // else entirely.
        $outside = sys_get_temp_dir().'/sanitube-secret-'.bin2hex(random_bytes(4)).'.wav';
        file_put_contents($outside, 'not ours');

        $link = $this->root.'/innocent.wav';

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('This filesystem does not support symlinks.');
        }

        $this->engine->claiming($link);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('A symlink out of the root must be refused.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_ESCAPED_ROOT', $exception->reason);
        }

        $this->assertFileExists($outside);
        @unlink($outside);
    }

    #[Test]
    public function the_root_itself_is_not_an_output(): void
    {
        $this->engine->claiming($this->root);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('The root itself is not an output.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_ESCAPED_ROOT', $exception->reason);
        }
    }

    #[Test]
    public function a_directory_inside_the_root_is_not_a_rendered_track(): void
    {
        // The case containment alone does not catch: this *is* inside the
        // root, and it is still not something to open and stream. A socket or
        // a device reached by symlink resolves outside the root and is refused
        // as an escape; a plain directory has to be refused on its own.
        $inside = $this->root.'/a-directory.wav';
        mkdir($inside, 0o755, true);

        $this->engine->claiming($inside);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('A directory is not an output.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_NOT_A_FILE', $exception->reason);
        }

        @rmdir($inside);
    }

    #[Test]
    public function a_path_that_merely_shares_a_prefix_with_the_root_is_refused(): void
    {
        // `/var/gen` does not contain `/var/generated-elsewhere`. The
        // separator in the comparison is what makes that true.
        $sibling = $this->root.'-elsewhere';
        mkdir($sibling, 0o755, true);
        file_put_contents($sibling.'/take.wav', 'not ours');

        $this->assertSame('OUTPUT_ESCAPED_ROOT', ContainedPath::reject($this->root, $sibling.'/take.wav'));

        @unlink($sibling.'/take.wav');
        @rmdir($sibling);
    }

    #[Test]
    public function an_engine_that_reports_success_and_writes_nothing_is_refused(): void
    {
        $this->engine->claiming($this->root.'/never-written.wav');

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('A missing output must be refused.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_MISSING', $exception->reason);
        }
    }

    #[Test]
    public function an_output_over_the_configured_size_is_refused_before_it_is_read(): void
    {
        config(['generation.worker.max_output_bytes' => 4]);

        $this->engine->producing('a great deal more than four bytes');

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('An oversized output must be refused.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_TOO_LARGE', $exception->reason);
        }

        // Nothing was staged: a limit checked while streaming is a limit that
        // has already cost the transfer.
        $this->assertSame([], $this->store->files('staging'));
    }

    #[Test]
    public function an_empty_output_is_refused(): void
    {
        $this->engine->producing('');

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('An empty output must be refused.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('OUTPUT_EMPTY', $exception->reason);
        }
    }

    #[Test]
    public function an_engine_failure_carries_a_code_and_never_its_own_words(): void
    {
        // ACE-Step's message is `f"Error generating audio: {str(e)}"` around a
        // Python traceback, and a client exception quotes the request.
        $this->engine->failing();

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('An engine failure must refuse.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('ENGINE_REPORTED_FAILURE', $exception->reason);
            $this->assertStringNotContainsString('fake', $exception->getMessage());
        }
    }

    #[Test]
    public function an_unreachable_engine_is_a_reason_and_not_a_crash(): void
    {
        $this->engine->unreachable();

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('An unreachable engine must refuse.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('ENGINE_UNREACHABLE', $exception->reason);
        }
    }

    #[Test]
    public function a_worker_with_no_output_root_refuses_every_generation(): void
    {
        // An unresolvable root refuses rather than widening. The alternative --
        // falling back to a temp directory, or to the current one -- would be a
        // misconfiguration that looks like it works.
        config(['generation.acestep.output_root' => '']);

        try {
            $this->worker()->handle($this->request(), 'abc123');
            $this->fail('An unconfigured root must refuse.');
        } catch (WorkerGenerationFailed $exception) {
            $this->assertSame('WORKER_OUTPUT_ROOT_NOT_CONFIGURED', $exception->reason);
        }

        $this->assertSame(0, $this->engine->calls, 'The engine must not even be asked.');
    }

    // ------------------------------------------- what the engine is never told

    #[Test]
    public function a_generation_request_cannot_reach_a_checkpoint_a_device_or_a_path(): void
    {
        // Asserted against the shape of the request the worker builds, because
        // this is a boundary that erodes by somebody adding one convenient
        // passthrough. ACE-Step takes `checkpoint_path` and `device_id` from
        // its client; a prompt that could set them would be a prompt that can
        // read a file and choose a GPU.
        // Comments stripped first, as FinancialScopeTest does for the same
        // reason: that file names these fields in order to explain why it has
        // none of them, and a guard that could not tell prose from code would
        // chase the explanation out of the repository.
        $source = $this->code('src/MusicGeneration/Worker/MusicGenerationJobHandler.php');

        // WRK-001 moved the rules onto the handler, beside the code that would
        // otherwise use these fields -- which is where a security boundary
        // belongs.
        foreach (['checkpoint', 'device', 'output_path', 'infer_step', 'guidance'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('A worker job payload must have no [%s] field.', $forbidden),
            );
        }
    }

    #[Test]
    public function no_part_of_a_request_can_reach_the_checkpoint_the_device_or_the_parameters(): void
    {
        // **The sharpest edge in the adapter.** ACE-Step's reference server
        // takes `checkpoint_path` and `device_id` from its client, so whoever
        // fills them chooses which file the engine loads and which GPU it runs
        // on. `GenerationRequest::$parameters` is the field a careless caller
        // would reach for -- it is explicitly "provider-specific extras" -- so
        // this proves it goes nowhere.
        //
        // Behavioural, not a source scan: what matters is the body that leaves,
        // and a scan would pass on a file that read the parameters by a name it
        // did not think to look for.
        config([
            'generation.acestep.endpoint' => 'http://engine.test',
            'generation.acestep.checkpoint_path' => '/opt/ours',
            'generation.acestep.device_id' => 3,
            'generation.acestep.defaults' => ['infer_step' => 60],
        ]);

        Http::fake(['engine.test/*' => Http::response(['status' => 'success', 'output_path' => '/tmp/x.wav', 'message' => ''])]);

        $engine = new HttpAceStepEngine($this->app->make(HttpFactory::class), (array) config('generation.acestep'));

        $engine->render(
            new GenerationRequest(
                prompt: 'a ballad',
                parameters: [
                    'checkpoint_path' => '/etc/shadow',
                    'device_id' => 99,
                    'infer_step' => 100000,
                    'output_path' => '/etc/cron.d/anything',
                ],
            ),
            '/var/lib/sanitube/generation/ours.wav',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            // The worker's, in every case.
            $this->assertSame('/opt/ours', $body['checkpoint_path'] ?? null);
            $this->assertSame(3, $body['device_id'] ?? null);
            $this->assertSame(60, $body['infer_step'] ?? null);
            $this->assertSame('/var/lib/sanitube/generation/ours.wav', $body['output_path'] ?? null);

            return true;
        });
    }

    #[Test]
    public function nothing_in_this_ticket_interpolates_a_shell(): void
    {
        // The engine contract is HTTP and the file is opened by path with PHP's
        // own functions, so there is no command line for a filename to escape
        // from. Asserted rather than assumed.
        foreach ([
            'src/MusicGeneration/AceStep/HttpAceStepEngine.php',
            'src/MusicGeneration/AceStep/ContainedPath.php',
            'src/MusicGeneration/Worker/RunGenerationOnWorker.php',
            'src/MusicGeneration/Worker/MusicGenerationJobHandler.php',
            'src/Worker/Http/Controllers/RunWorkerJobController.php',
            'src/Worker/Client/WorkerClient.php',
            'src/MusicGeneration/Providers/SelfHostedAceStepProvider.php',
        ] as $file) {
            $source = $this->code($file);

            foreach (['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen(', 'system('] as $dangerous) {
                $this->assertStringNotContainsString($dangerous, $source, basename($file));
            }
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * A file's source with its comments removed.
     *
     * These files discuss checkpoints, devices and shells precisely in order to
     * say they touch none of them, so a scan that read the prose would fail on
     * the explanation.
     */
    private function code(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function worker(): RunGenerationOnWorker
    {
        return $this->app->make(RunGenerationOnWorker::class);
    }

    private function request(): GenerationRequest
    {
        return new GenerationRequest(prompt: 'a quiet ballad', stylePrompt: 'ambient, piano');
    }
}
