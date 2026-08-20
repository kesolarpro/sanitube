<?php

declare(strict_types=1);

namespace Tests\Feature\Transcription;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Foundation\Providers\ProviderCertification;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Providers\NullTranscriptionProvider;
use SaniTube\Transcription\Providers\OpenAiTranscriptionProvider;
use SaniTube\Transcription\Services\TranscribeAsset;
use SaniTube\Transcription\TranscriptionManager;
use Tests\TestCase;

/**
 * TRN-002 — speech to text through OpenAI.
 *
 * **Written against the official OpenAPI specification** (`openai/openai-openapi`,
 * the `/audio/transcriptions` path) rather than against recollection, and the
 * fixtures below are shaped by that document: multipart, `file` and `model`
 * required, `verbose_json` returning `text`, `language`, `duration` and
 * `segments`, and segment `start`/`end` as numbers **in seconds**.
 *
 * Two things this file is careful *not* to assert, because the specification
 * does not establish them: a list of accepted audio formats, and a maximum
 * upload size. Both are widely repeated in secondary sources. The size ceiling
 * here is therefore tested as *configured local policy*, which is what it is.
 *
 * The remaining tests are containment. A transcription call carries a bearer
 * token and a customer's audio, and the two ways that leaks are an exception
 * message quoting the request and an error body quoting it back.
 */
final class OpenAiTranscriptionProviderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-transcription-0123456789abcdefghij';

    private const BASE = 'https://api.openai.example/v1';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this file may reach the network. A request the fake does
        // not describe is a failure rather than a real, billed call.
        Http::preventStrayRequests();
    }

    // ------------------------------------------------------- configuration

    #[Test]
    public function a_fresh_install_still_transcribes_with_nobody(): void
    {
        // The shipped default. A supplier is an enrichment, and an
        // installation that configures none must not look broken.
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertArrayHasKey('SANITUBE_TRANSCRIPTION_PROVIDER', $values);
        $this->assertSame('none', $values['SANITUBE_TRANSCRIPTION_PROVIDER']);

        config(['transcription.provider' => $values['SANITUBE_TRANSCRIPTION_PROVIDER']]);

        $this->assertInstanceOf(NullTranscriptionProvider::class, $this->manager()->default());
    }

    #[Test]
    public function the_shipped_configuration_builds_the_adapter_when_it_is_chosen(): void
    {
        // The wiring itself: naming the supplier in configuration is the whole
        // of what an operator has to do. Before this ticket the manager threw
        // for every name, so a configured `openai` was an error rather than a
        // provider.
        config([
            'transcription.provider' => 'openai',
            'transcription.providers.openai.key' => self::KEY,
            'transcription.providers.openai.base_url' => self::BASE,
        ]);

        $provider = $this->manager()->default();

        $this->assertInstanceOf(OpenAiTranscriptionProvider::class, $provider);
        $this->assertSame('openai', $provider->name());
    }

    #[Test]
    public function a_key_alone_never_certifies_the_provider(): void
    {
        // The distinction the certification enum exists for. A pasted key says
        // somebody pasted a key; it does not say the account exists, the model
        // is permitted or the quota is unspent. Only a real call says that, and
        // asking this question must not make one.
        $configured = $this->provider();

        $this->assertSame(ProviderCertification::ConfiguredUncertified, $configured->certification());
        $this->assertNotSame(ProviderCertification::Certified, $configured->certification());
        $this->assertTrue($configured->isAvailable());

        foreach ([['key' => null], ['base_url' => null], ['key' => '  '], ['base_url' => '']] as $missing) {
            $partial = $this->provider($missing);

            $this->assertSame(
                ProviderCertification::NotConfigured,
                $partial->certification(),
                'Half-configured is not configured.',
            );
            $this->assertFalse($partial->isAvailable());
        }
    }

    #[Test]
    public function an_unconfigured_provider_refuses_before_it_calls_anything(): void
    {
        $this->expectException(TranscriptionFailed::class);

        // No Http::fake at all: preventStrayRequests turns any attempted call
        // into a failure, so this passing means nothing was attempted.
        $this->provider(['key' => null])->transcribe($this->audioFile());
    }

    // ------------------------------------------------------- the wire format

    #[Test]
    public function the_request_matches_the_documented_contract(): void
    {
        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $this->provider()->transcribe($this->audioFile('take-one.wav'), 'fr');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::BASE.'/audio/transcriptions', $request->url());
            $this->assertTrue($request->isMultipart(), 'The endpoint takes multipart/form-data.');
            $this->assertSame('Bearer '.self::KEY, $request->header('Authorization')[0]);

            $parts = $this->parts($request);

            $this->assertSame('whisper-1', $parts['model']);
            $this->assertSame('verbose_json', $parts['response_format']);
            $this->assertSame('fr', $parts['language']);
            $this->assertArrayHasKey('file', $parts);
            $this->assertSame('take-one.wav', $this->filename($request));

            return true;
        });
    }

    #[Test]
    public function a_language_hint_is_sent_only_when_there_is_one(): void
    {
        // Absent, and blank, are both "we do not know". Sending an empty
        // `language` would narrow the model's search to nothing in particular
        // and is not the same request.
        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        foreach ([null, ''] as $hint) {
            $this->provider()->transcribe($this->audioFile(), $hint);
        }

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('language', $this->parts($request)));
    }

    #[Test]
    public function the_configured_model_is_the_one_that_is_sent(): void
    {
        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $result = $this->provider(['model' => 'gpt-4o-transcribe'])->transcribe($this->audioFile());

        Http::assertSent(fn (Request $request): bool => $this->parts($request)['model'] === 'gpt-4o-transcribe');

        // Recorded on the result too, because a transcript that does not say
        // which model produced it cannot be re-judged when the model changes.
        $this->assertSame('gpt-4o-transcribe', $result->model);
    }

    // -------------------------------------------------------- the response

    #[Test]
    public function seconds_in_the_response_become_milliseconds_here(): void
    {
        // The specification is explicit that `start` and `end` are seconds.
        // Storing them unconverted puts every segment a thousand times too
        // early, and nothing downstream would notice until somebody looked at
        // a timeline.
        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $result = $this->provider()->transcribe($this->audioFile());

        $this->assertSame('Bonjour tout le monde. Encore une fois.', $result->text);
        $this->assertSame('fr', $result->languageCode);
        $this->assertSame(9, $result->durationSeconds);

        $this->assertCount(2, $result->segments);
        $this->assertSame(0, $result->segments[0]->startMs);
        $this->assertSame(3120, $result->segments[0]->endMs);
        $this->assertSame('Bonjour tout le monde.', $result->segments[0]->text);
        $this->assertSame(3120, $result->segments[1]->startMs);
        $this->assertSame(8750, $result->segments[1]->endMs);
    }

    #[Test]
    public function a_segment_missing_a_timing_is_dropped_rather_than_placed_at_zero(): void
    {
        // A zero start is a real position. Defaulting a missing one would put
        // that text at the beginning of the recording and it would look
        // entirely plausible there.
        $payload = $this->payload();
        $payload['segments'][] = ['id' => 2, 'end' => 12.0, 'text' => 'no start'];
        $payload['segments'][] = ['id' => 3, 'start' => 12.0, 'text' => 'no end'];
        $payload['segments'][] = ['id' => 4, 'start' => 13.0, 'end' => 14.0];
        $payload['segments'][] = 'not a segment at all';

        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($payload)]);

        $result = $this->provider()->transcribe($this->audioFile());

        $this->assertCount(2, $result->segments);
    }

    #[Test]
    public function a_response_without_usable_text_is_a_refusal_rather_than_an_empty_transcript(): void
    {
        foreach ([['text' => null], ['text' => ['not', 'a', 'string']], []] as $broken) {
            $payload = $broken === [] ? ['language' => 'fr'] : array_merge($this->payload(), $broken);

            Http::fake([self::BASE.'/audio/transcriptions' => Http::response($payload)]);

            try {
                $this->provider()->transcribe($this->audioFile());
                $this->fail('An unreadable response must not become a transcript.');
            } catch (TranscriptionFailed $failed) {
                $this->assertSame('TRANSCRIPTION_UNREADABLE', $failed->reason);
            }
        }
    }

    #[Test]
    public function a_response_with_no_segments_is_honest_rather_than_inventive(): void
    {
        // `json` responses, and any model that returns no timings, land here.
        // Timings are not derivable from text, and inventing them would be a
        // fabrication that looks like data.
        $payload = $this->payload();
        unset($payload['segments']);

        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($payload)]);

        $result = $this->provider()->transcribe($this->audioFile());

        $this->assertSame([], $result->segments);
        $this->assertNotSame('', $result->text);
    }

    // ------------------------------------------------------------ leaking

    #[Test]
    public function a_failing_response_body_is_never_carried_out_of_the_adapter(): void
    {
        // A vendor's error routinely echoes the request back, and the request
        // carried a bearer token. There is no reading of that body which is
        // safe to keep, so none of it is kept.
        Http::fake([
            self::BASE.'/audio/transcriptions' => Http::response([
                'error' => [
                    'message' => 'Incorrect API key provided: '.self::KEY,
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);

        try {
            $this->provider()->transcribe($this->audioFile());
            $this->fail('A 401 must not be treated as a transcript.');
        } catch (TranscriptionFailed $failed) {
            $this->assertSame('TRANSCRIPTION_CALL_FAILED', $failed->reason);
            $this->assertStringNotContainsString(self::KEY, $failed->getMessage());
            $this->assertStringNotContainsString('invalid_api_key', $failed->getMessage());
            $this->assertStringNotContainsString('Incorrect API key', $failed->getMessage());
        }
    }

    #[Test]
    public function a_transport_failure_is_redacted_before_it_is_raised(): void
    {
        // The HTTP client quotes the request it sent, and that request had an
        // Authorization header on it. A failing call is exactly the output
        // somebody pastes into a support thread.
        Http::fake([
            self::BASE.'/audio/transcriptions' => function (): never {
                throw new ConnectionException(
                    'cURL error 28: Operation timed out sending Authorization: Bearer '.self::KEY,
                );
            },
        ]);

        try {
            $this->provider()->transcribe($this->audioFile());
            $this->fail('A transport failure must not be treated as a transcript.');
        } catch (TranscriptionFailed $failed) {
            $this->assertSame('TRANSCRIPTION_CALL_FAILED', $failed->reason);
            $this->assertStringNotContainsString(self::KEY, $failed->getMessage());
            $this->assertStringContainsString('[redacted]', $failed->getMessage());
        }
    }

    // ------------------------------------------------------------- policy

    #[Test]
    public function an_oversized_file_is_refused_before_it_is_uploaded(): void
    {
        // Configured local policy, *not* a documented API limit -- the
        // specification states no maximum. Checked before the transfer because
        // finding out from the supplier costs the whole upload.
        $provider = $this->provider(['max_bytes' => 8]);

        try {
            $provider->transcribe($this->audioFile('long.wav', str_repeat('a', 64)));
            $this->fail('An oversized upload must be refused.');
        } catch (TranscriptionFailed $failed) {
            $this->assertSame('TRANSCRIPTION_SOURCE_TOO_LARGE', $failed->reason);
        }

        // Nothing was attempted: preventStrayRequests would have failed first.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_zero_ceiling_means_the_supplier_decides(): void
    {
        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $result = $this->provider(['max_bytes' => 0])->transcribe($this->audioFile('long.wav', str_repeat('a', 4096)));

        $this->assertNotSame('', $result->text);
    }

    #[Test]
    public function a_file_that_cannot_be_read_is_refused_without_a_call(): void
    {
        try {
            $this->provider()->transcribe(sys_get_temp_dir().'/sanitube-does-not-exist-'.bin2hex(random_bytes(6)));
            $this->fail('A missing file must be refused.');
        } catch (TranscriptionFailed $failed) {
            $this->assertSame('TRANSCRIPTION_SOURCE_UNREADABLE', $failed->reason);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------- the production path

    #[Test]
    public function the_domain_service_reaches_the_real_adapter_through_configuration(): void
    {
        // **WHO CALLS THIS IN PRODUCTION?** `TranscribeAsset` does, and only
        // through the manager -- no domain service names a vendor. This test
        // is the proof of that whole chain rather than of the adapter alone:
        // configuration says `openai`, the container resolves the service, and
        // a real HTTP request leaves for the documented endpoint carrying the
        // documented fields. Nothing here substitutes a provider.
        config([
            'transcription.provider' => 'openai',
            'transcription.providers.openai.key' => self::KEY,
            'transcription.providers.openai.base_url' => self::BASE,
        ]);
        $this->app->forgetInstance(TranscriptionManager::class);

        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $transcript = $this->app->make(TranscribeAsset::class)->handle($this->asset(), 'fr');

        $this->assertInstanceOf(Transcript::class, $transcript);
        $this->assertSame('openai', $transcript->provider);
        $this->assertSame(OpenAiTranscriptionProvider::VERSION, $transcript->provider_version);
        $this->assertSame('whisper-1', $transcript->model);
        $this->assertSame('fr', $transcript->detected_language);
        $this->assertSame(2, $transcript->segments()->count());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/audio/transcriptions');
    }

    #[Test]
    public function a_second_pass_over_the_same_asset_does_not_pay_twice(): void
    {
        // Idempotence is what stops a retried job from re-billing a whole
        // catalogue. Asserted on the wire, because a cached row that still
        // made the call would look identical from the database.
        config([
            'transcription.provider' => 'openai',
            'transcription.providers.openai.key' => self::KEY,
            'transcription.providers.openai.base_url' => self::BASE,
        ]);
        $this->app->forgetInstance(TranscriptionManager::class);

        Http::fake([self::BASE.'/audio/transcriptions' => Http::response($this->payload())]);

        $asset = $this->asset();
        $service = $this->app->make(TranscribeAsset::class);

        $service->handle($asset);
        $service->handle($asset);

        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------ fixtures

    private function manager(): TranscriptionManager
    {
        $this->app->forgetInstance(TranscriptionManager::class);

        return $this->app->make(TranscriptionManager::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): OpenAiTranscriptionProvider
    {
        return new OpenAiTranscriptionProvider(array_merge([
            'key' => self::KEY,
            'base_url' => self::BASE,
        ], $overrides));
    }

    /**
     * The `verbose_json` shape the official specification describes.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'task' => 'transcribe',
            'language' => 'fr',
            'duration' => 8.75,
            'text' => 'Bonjour tout le monde. Encore une fois.',
            'segments' => [
                [
                    'id' => 0,
                    'seek' => 0,
                    'start' => 0.0,
                    'end' => 3.12,
                    'text' => 'Bonjour tout le monde.',
                    'tokens' => [50364, 2425],
                    'temperature' => 0.0,
                    'avg_logprob' => -0.28,
                    'compression_ratio' => 1.4,
                    'no_speech_prob' => 0.01,
                ],
                [
                    'id' => 1,
                    'seek' => 0,
                    'start' => 3.12,
                    'end' => 8.75,
                    'text' => 'Encore une fois.',
                    'tokens' => [50520, 4412],
                    'temperature' => 0.0,
                    'avg_logprob' => -0.31,
                    'compression_ratio' => 1.4,
                    'no_speech_prob' => 0.02,
                ],
            ],
        ];
    }

    private function audioFile(string $filename = 'master.wav', string $bytes = 'audio'): string
    {
        $directory = sys_get_temp_dir().'/sanitube-trn-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory.'/'.$filename;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function asset(): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, 'master.wav'), $stream);
    }

    /**
     * Multipart parts by name, so a test reads like the form it describes.
     *
     * @return array<string, string>
     */
    private function parts(Request $request): array
    {
        $parts = [];

        foreach ($request->data() as $part) {
            if (is_array($part) && is_string($part['name'] ?? null)) {
                $parts[$part['name']] = is_string($part['contents'] ?? null) ? $part['contents'] : 'file';
            }
        }

        return $parts;
    }

    private function filename(Request $request): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === 'file') {
                return is_string($part['filename'] ?? null) ? $part['filename'] : null;
            }
        }

        return null;
    }
}
