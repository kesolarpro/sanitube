<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Media\Analyzers\StrategyAudioAnalyzer;
use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Execution\ExecutionPlace;
use SaniTube\Media\Execution\ExecutionStrategy;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Media\Models\AudioFingerprint;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Observability\Capabilities\CapabilityStatus;
use SaniTube\Observability\Capabilities\Detectors\MediaExecutionDetector;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Worker\Client\WorkerClient;
use Tests\TestCase;

/**
 * WRK-002 — where heavy media work runs, and who decides.
 *
 * SaniTube installs on a cPanel account with no FFmpeg and on a VPS with a full
 * toolchain. Until now it had one answer — run it here — and a shared host went
 * without.
 *
 * **The choice is configuration and never a branch in domain logic.** Nothing
 * in `AnalyzeAsset` or `FingerprintAsset` knows which machine ran the binary,
 * and the tests below are mostly about the difference between what an operator
 * *asked for* and what is *actually possible*.
 *
 * The rule that carries the ticket: **availability is never inferred from
 * configuration.** A worker URL and a token prove somebody typed two settings.
 */
final class MediaExecutionStrategyTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // ------------------------------------------------------------- A: local

    #[Test]
    public function a_local_only_installation_still_analyses(): void
    {
        // Proof A. Nothing about WRK-002 may cost an installation that was
        // working before it the thing that was working.
        config(['media.execution' => 'local', 'worker.url' => '', 'worker.token' => '']);

        $this->app->instance('media.analyzer.local', new FakeAudioAnalyzer);

        $asset = $this->verifiedAsset();

        $analysis = $this->app->make(AnalyzeAsset::class)->handle($asset);

        $this->assertInstanceOf(AudioAnalysis::class, $analysis);
        $this->assertTrue($analysis->succeeded);
        $this->assertSame(ExecutionPlace::Local, $this->analyzerPlace());
    }

    #[Test]
    public function local_means_local_even_when_a_healthy_worker_is_offering(): void
    {
        // An operator who wrote `local` gets local. A worker sitting there
        // advertising the capability is not an invitation: the setting exists
        // so somebody can keep work on a machine they have reasons to trust,
        // and quietly overriding it would defeat the only reason to write it.
        $this->workerAdvertising(['MEDIA_PROBE']);

        config(['media.execution' => 'local']);

        $this->assertSame(ExecutionPlace::Local, $this->analyzerPlace());

        $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/jobs/media-probe'));
    }

    #[Test]
    public function core_boots_and_analyses_nothing_with_no_tools_and_no_worker(): void
    {
        // Proof G. A cPanel account with neither still stores and catalogues;
        // it simply cannot measure, and that is a supported deployment.
        config(['media.execution' => 'auto', 'worker.url' => '', 'worker.token' => '']);

        $this->app->instance('media.analyzer.local', (new FakeAudioAnalyzer)->unavailable());

        $this->assertSame(ExecutionPlace::Nowhere, $this->analyzerPlace());
        $this->assertFalse($this->app->make(AudioAnalyzer::class)->isAvailable());

        // Optional, never blocking: this is not a broken installation.
        $capability = $this->detector()->detect();

        $this->assertSame(CapabilityStatus::Optional, $capability->status);
        $this->assertStringContainsString('UNAVAILABLE', (string) $capability->detail);
    }

    // ------------------------------------------------------------ B: remote

    #[Test]
    public function a_healthy_worker_that_advertises_the_capability_does_the_probing(): void
    {
        // Proof B, through the real HTTP boundary: Core's outbound call lands
        // on this application's own worker route.
        config(['media.execution' => 'auto']);

        $this->workerAdvertising(['MEDIA_PROBE']);

        $this->assertSame(ExecutionPlace::RemoteWorker, $this->analyzerPlace());

        $asset = $this->verifiedAsset();

        $analysis = $this->app->make(AnalyzeAsset::class)->handle($asset);

        $this->assertInstanceOf(AudioAnalysis::class, $analysis);
        $this->assertTrue($analysis->succeeded);

        // Measured by the tool, wherever it ran. The row says what produced
        // the facts, not which host was asked.
        $this->assertSame('ffprobe', $analysis->analyzer);
        $this->assertSame(1234, $analysis->duration_ms);
        $this->assertSame(44100, $analysis->sample_rate);
    }

    #[Test]
    public function the_bytes_never_touch_this_machine_when_a_worker_reads_storage(): void
    {
        // The reason a storage key crosses the boundary rather than a file. On
        // a cPanel account talking to R2, streaming down and back up was twice
        // the egress for no benefit.
        config(['media.execution' => 'auto']);

        $this->workerAdvertising(['MEDIA_PROBE']);

        $before = count(glob(sys_get_temp_dir().'/sanitube-analysis-*') ?: []);

        $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        $this->assertSame($before, count(glob(sys_get_temp_dir().'/sanitube-analysis-*') ?: []));
    }

    // -------------------------------------------------------- C: auto falls back

    #[Test]
    public function auto_falls_back_to_this_machine_when_the_worker_cannot_be_reached(): void
    {
        // Proof C. `auto` is the shipped default precisely because it is the
        // only strategy that is right on a bare cPanel account, a full VPS, and
        // an installation whose worker is temporarily down.
        config(['media.execution' => 'auto', 'worker.url' => 'http://worker.test', 'worker.token' => 'tok']);

        Http::fake(['worker.test/*' => Http::response('', 500)]);

        $this->app->instance('media.analyzer.local', new FakeAudioAnalyzer);

        $this->assertSame(ExecutionPlace::Local, $this->analyzerPlace());

        $analysis = $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        $this->assertInstanceOf(AudioAnalysis::class, $analysis);
        $this->assertTrue($analysis->succeeded);
    }

    #[Test]
    public function falling_back_is_reported_as_degraded_and_never_as_green(): void
    {
        // Real work is happening, on the machine the operator did not choose.
        // A green tick here is how somebody spends an afternoon wondering why a
        // shared host is slow.
        config(['media.execution' => 'auto', 'worker.url' => 'http://worker.test', 'worker.token' => 'tok']);

        Http::fake(['worker.test/*' => Http::response('', 500)]);

        $this->app->instance('media.analyzer.local', new FakeAudioAnalyzer);

        $capability = $this->detector()->detect();

        $this->assertSame(CapabilityStatus::Degraded, $capability->status);
        $this->assertStringContainsString('REMOTE_WORKER_UNAVAILABLE_LOCAL_AVAILABLE', (string) $capability->detail);
        $this->assertStringContainsString('handshake', (string) $capability->remediation);
    }

    // ------------------------------------------------- D: explicit means explicit

    #[Test]
    public function an_explicitly_selected_worker_does_not_quietly_use_this_machine(): void
    {
        // Proof D. An operator who selected a worker has usually done so for a
        // reason — a shared host whose CPU limit ffprobe trips — that a silent
        // fallback would defeat without telling anybody.
        config(['media.execution' => 'remote_worker', 'worker.url' => 'http://worker.test', 'worker.token' => 'tok']);

        Http::fake(['worker.test/*' => Http::response('', 500)]);

        // The local tool is present and deliberately not used.
        $this->app->instance('media.analyzer.local', new FakeAudioAnalyzer);

        $this->assertSame(ExecutionPlace::Nowhere, $this->analyzerPlace());
        $this->assertFalse($this->app->make(AudioAnalyzer::class)->isAvailable());

        $this->assertNull($this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset()));

        $capability = $this->detector()->detect();

        $this->assertSame(CapabilityStatus::Optional, $capability->status);
        $this->assertStringContainsString('`remote_worker` means what it says', (string) $capability->detail);
    }

    // ------------------------------------------- E: capability, not configuration

    #[Test]
    public function a_worker_that_does_not_advertise_the_capability_is_not_sent_the_job(): void
    {
        // Proof E, and the rule that carries the ticket. The worker is
        // reachable, the token is right, and it does not do this work.
        config(['media.execution' => 'auto']);

        $this->workerAdvertising(['MUSIC_GENERATION']);

        $this->app->instance('media.analyzer.local', new FakeAudioAnalyzer);

        $this->assertSame(ExecutionPlace::Local, $this->analyzerPlace());

        $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        // Not one job was posted to the boundary.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/jobs/media-probe'));
    }

    // --------------------------------------------------- F: a malformed answer

    #[Test]
    public function a_worker_answering_in_an_unexpected_shape_fails_structurally(): void
    {
        // Proof F. A proxy returning a login page, a load balancer answering
        // with a string, a worker mid-upgrade — each is a 200 carrying
        // something that is not a result, and none of them may become a
        // TypeError inside a queue worker.
        config(['media.execution' => 'remote_worker', 'worker.url' => 'http://worker.test', 'worker.token' => 'tok']);

        Http::fake([
            'worker.test/api/worker' => Http::response(['name' => 'w', 'capabilities' => ['MEDIA_PROBE']]),
            'worker.test/api/worker/jobs/*' => Http::response('"not a result"', 200, ['Content-Type' => 'application/json']),
        ]);

        $analysis = $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        // Recorded as a failed analysis, which is what a failed analysis is.
        $this->assertInstanceOf(AudioAnalysis::class, $analysis);
        $this->assertFalse($analysis->succeeded);
    }

    // -------------------------------------------------- what the worker does

    #[Test]
    public function the_worker_leaves_no_copy_behind(): void
    {
        // It streams the object to its own disk so a local tool can open it. A
        // worker that leaked one copy per probe would fill a disk in an
        // afternoon -- on a shared host, considerably sooner.
        config(['media.execution' => 'auto']);

        $this->workerAdvertising(['MEDIA_PROBE']);

        $before = count(glob(sys_get_temp_dir().'/sanitube-worker-*') ?: []);

        $this->app->make(AnalyzeAsset::class)->handle($this->verifiedAsset());

        $this->assertSame($before, count(glob(sys_get_temp_dir().'/sanitube-worker-*') ?: []));
    }

    #[Test]
    public function an_object_the_worker_cannot_find_is_named_rather_than_discovered(): void
    {
        // Core and the worker disagreeing about what is in storage is a
        // configuration fault -- two different buckets, usually -- and it
        // deserves its own word rather than surfacing as an unreadable stream.
        config(['media.execution' => 'auto']);

        $this->workerAdvertising(['MEDIA_PROBE']);

        $asset = $this->verifiedAsset();
        $this->store->delete($asset->path);

        $analysis = $this->app->make(AnalyzeAsset::class)->handle($asset);

        $this->assertInstanceOf(AudioAnalysis::class, $analysis);
        $this->assertFalse($analysis->succeeded);

        // The reason, not merely the failure. "The bucket does not have it" and
        // "it is there and cannot be read" have different remedies -- two
        // storage configurations against one, versus permissions -- and an
        // operator reading this row is the person who has to choose.
        $this->assertSame('OBJECT_NOT_IN_STORAGE', $analysis->failure_message);
    }

    #[Test]
    public function a_malformed_fingerprint_answer_is_refused_rather_than_stored(): void
    {
        // A fingerprint is evidence the deduplication engine compares. One
        // that arrived in a shape this build did not understand would be
        // evidence of nothing, sitting in a table that says otherwise.
        config([
            'media.execution' => 'remote_worker',
            'worker.url' => 'http://worker.test',
            'worker.token' => 'tok',
        ]);

        Http::fake([
            'worker.test/api/worker' => Http::response(['name' => 'w', 'capabilities' => ['AUDIO_FINGERPRINT']]),
            'worker.test/api/worker/jobs/*' => Http::response(['fingerprint' => '', 'duration_seconds' => 'nine']),
        ]);

        $this->app->forgetInstance(AudioFingerprinter::class);
        $this->app->forgetInstance(WorkerClient::class);

        $this->assertNull($this->app->make(FingerprintAsset::class)->handle($this->verifiedAsset()));

        // Recorded as absent, never as a fingerprint nobody can compare.
        $this->assertSame(0, AudioFingerprint::query()->count());
    }

    // ------------------------------------------------------------- the setting

    #[Test]
    public function an_unreadable_strategy_is_auto_rather_than_a_boot_failure(): void
    {
        // A typo in a .env on a shared host must not take an installation down,
        // and `auto` is the strategy that copes with the widest range.
        foreach (['', 'nonsense', null, 'REMOTE-WORKER'] as $configured) {
            $parsed = ExecutionStrategy::parse($configured);

            $this->assertInstanceOf(ExecutionStrategy::class, $parsed);
        }

        $this->assertSame(ExecutionStrategy::RemoteWorker, ExecutionStrategy::parse('REMOTE-WORKER'));
        $this->assertSame(ExecutionStrategy::Auto, ExecutionStrategy::parse('nonsense'));
        $this->assertSame(ExecutionStrategy::Local, ExecutionStrategy::parse(' Local '));
    }

    #[Test]
    public function no_domain_service_names_a_machine(): void
    {
        // The property the whole ticket exists to establish: a caller must not
        // be able to tell. Asserted mechanically, because this is a boundary
        // that erodes by somebody adding one convenient check.
        foreach ([
            'src/Media/Services/AnalyzeAsset.php',
            'src/Media/Services/FingerprintAsset.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));

            foreach (['WorkerClient', 'WorkerCapability', 'RemoteAudio', 'ExecutionPlace'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file));
            }
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * A worker that answers its handshake with exactly these capabilities, and
     * runs real jobs through this application's own boundary.
     *
     * @param  list<string>  $capabilities
     */
    private function workerAdvertising(array $capabilities): void
    {
        config(['worker.url' => 'http://worker.test', 'worker.token' => 'a-worker-token']);

        // The worker's own local analyser, with a value this test can name --
        // so a fact crossing the boundary is provably the one the far side
        // measured rather than a default either side happens to share.
        $this->app->instance('media.analyzer.local', (new FakeAudioAnalyzer)->willReturn(
            AudioAnalysisResult::measured(durationMs: 1234, codec: 'pcm_s16le', sampleRate: 44100, channels: 2),
        ));
        $this->app->forgetInstance(WorkerClient::class);

        Http::fake(['worker.test/*' => function ($request) use ($capabilities) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'name' => 'gpu-one',
                    'version' => 'test',
                    'capabilities' => $capabilities,
                    'tools' => ['ffprobe' => 'ffprobe version 6.1'],
                ]);
            }

            // The real route, the real middleware, the real handler.
            $response = $this->postJson(
                '/api/worker/jobs/media-probe',
                (array) $request->data(),
                ['X-SaniTube-Worker-Token' => 'a-worker-token'],
            );

            return Http::response($response->json(), $response->getStatusCode());
        }]);
    }

    private function analyzerPlace(): ExecutionPlace
    {
        $this->app->forgetInstance(AudioAnalyzer::class);
        $this->app->forgetInstance(WorkerClient::class);

        $analyzer = $this->app->make(AudioAnalyzer::class);

        $this->assertInstanceOf(StrategyAudioAnalyzer::class, $analyzer);

        return $analyzer->place();
    }

    private function detector(): MediaExecutionDetector
    {
        $this->app->forgetInstance(WorkerClient::class);
        $this->app->forgetInstance(AudioAnalyzer::class);
        $this->app->forgetInstance(AudioFingerprinter::class);

        return $this->app->make(MediaExecutionDetector::class);
    }

    private function verifiedAsset(): Asset
    {
        $bytes = 'RIFF....WAVEfmt ';
        $stored = $this->store->put('masters/take.wav', $bytes);

        return Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'disk' => 'memory',
            'path' => $stored->key,
            'original_filename' => 'take.wav',
            'mime_type' => 'audio/wav',
            'byte_size' => $stored->size,
            'sha256' => hash('sha256', $bytes),
            'status' => AssetStatus::Verified,
        ]);
    }
}
