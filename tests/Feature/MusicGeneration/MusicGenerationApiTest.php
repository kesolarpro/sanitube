<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Models\Track;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Jobs\ImportGenerationResultJob;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The Studio's API, and what it must never say.
 *
 * A generation provider is a billed account. Its job identifiers, endpoints
 * and result URLs are all things that let somebody reach the vendor or the
 * audio without going through SaniTube, and a field published once is a field
 * a client depends on.
 */
final class MusicGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-internal-api-token-for-testing';

    private FakeMusicGenerationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'storage.default' => 'memory',
            'sanitube.api.internal_token' => self::TOKEN,
            'generation.default' => 'fake',
        ]);

        $this->app->make(StorageManager::class)->register('memory', new InMemoryStorageProvider('memory'));

        $this->provider = new FakeMusicGenerationProvider;
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', $this->provider);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        Queue::fake();
    }

    #[Test]
    public function the_generation_endpoints_refuse_a_caller_without_the_token(): void
    {
        $this->postJson('/api/v1/generations', ['prompt' => 'piano'])->assertUnauthorized();
        $this->postJson('/api/v1/generation/projects', ['name' => 'x'])->assertUnauthorized();
        $this->getJson('/api/v1/generation/projects')->assertUnauthorized();
    }

    #[Test]
    public function a_project_can_be_created_and_starts_as_a_draft(): void
    {
        $this->authenticated()->postJson('/api/v1/generation/projects', [
            'name' => 'Autumn ambient',
            'target_track_count' => 40,
            'default_style_prompt' => 'slow, warm, analogue',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Autumn ambient')
            // Nothing has been generated; ACTIVE would claim otherwise.
            ->assertJsonPath('data.status', 'DRAFT');
    }

    #[Test]
    public function starting_a_generation_answers_202_and_queues_the_submission(): void
    {
        // 202: the generation is recorded, the audio does not exist yet.
        $this->authenticated()->postJson('/api/v1/generations', ['prompt' => 'ambient piano'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.commercial_rights_status', 'UNKNOWN');

        Queue::assertPushed(SubmitMusicGenerationJob::class);
    }

    #[Test]
    public function an_installation_with_no_provider_refuses_with_a_reason(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $this->authenticated()->postJson('/api/v1/generations', ['prompt' => 'piano'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROVIDER_UNAVAILABLE');
    }

    #[Test]
    public function a_provider_nothing_configures_is_refused_rather_than_attempted(): void
    {
        $this->authenticated()->postJson('/api/v1/generations', [
            'prompt' => 'piano',
            'provider' => 'sunoo',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'UNKNOWN_PROVIDER');
    }

    #[Test]
    public function a_generation_can_be_cancelled_and_then_cannot_be_cancelled_again(): void
    {
        $generation = $this->submitted();

        $this->authenticated()->postJson('/api/v1/generations/'.$generation->uuid.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->authenticated()->postJson('/api/v1/generations/'.$generation->uuid.'/cancel')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NOT_CANCELLABLE');
    }

    #[Test]
    public function results_are_listed_and_selecting_one_queues_the_import(): void
    {
        $generation = $this->completed(takes: 2);

        $this->authenticated()->getJson('/api/v1/generations/'.$generation->uuid.'/results')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $result = $generation->results()->orderBy('id')->firstOrFail();

        $this->authenticated()->postJson('/api/v1/generation/results/'.$result->uuid.'/select')
            ->assertOk()
            ->assertJsonPath('data.selected', true);

        // Queued rather than done inline: a rendered master will not survive
        // an HTTP request.
        Queue::assertPushed(ImportGenerationResultJob::class);
        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function a_result_cannot_be_selected_before_its_generation_finishes(): void
    {
        $generation = $this->submitted();

        $result = $generation->results()->create([
            'provider_result_id' => 'early',
            'source_reference' => 'early.mp3',
        ]);

        $this->authenticated()->postJson('/api/v1/generation/results/'.$result->uuid.'/select')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'GENERATION_NOT_COMPLETE');
    }

    #[Test]
    public function no_response_carries_a_provider_job_id_or_a_result_url(): void
    {
        // The assertion that matters most here. A provider job identifier is a
        // handle on a billed job, and a result URL streams the audio without
        // going anywhere near SaniTube's signed-URL policy.
        $generation = $this->completed(takes: 2);
        $result = $generation->results()->firstOrFail();

        $responses = [
            $this->authenticated()->getJson('/api/v1/generations/'.$generation->uuid),
            $this->authenticated()->getJson('/api/v1/generations/'.$generation->uuid.'/results'),
        ];

        foreach ($responses as $response) {
            $raw = $response->content();

            foreach ([(string) $generation->provider_job_id, (string) $result->source_reference] as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $raw,
                    sprintf('[%s] appears in a generation response.', $secret),
                );
            }

            foreach ($this->keysIn((array) $response->json('data')) as $key) {
                $this->assertNotContains($key, [
                    'id', 'provider_job_id', 'provider_result_id', 'source_reference', 'raw',
                    'asset_id', 'generation_id', 'disk', 'path', 'bucket',
                ], sprintf('[%s] is published by a generation resource.', $key));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function keysIn(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysIn($value));
            }
        }

        return array_values(array_unique($keys));
    }

    private function authenticated(): self
    {
        return $this->withHeader((string) config('sanitube.api.token_header', 'X-SaniTube-Token'), self::TOKEN);
    }

    private function submitted(): MusicGeneration
    {
        return $this->app->make(SubmitMusicGeneration::class)->handle(
            $this->app->make(StartMusicGeneration::class)->handle(prompt: 'ambient piano'),
        );
    }

    private function completed(int $takes = 1): MusicGeneration
    {
        $this->provider->producing($takes);
        $generation = $this->submitted();
        $this->provider->complete((string) $generation->provider_job_id);

        return $this->app->make(PollMusicGeneration::class)->handle($generation);
    }
}
