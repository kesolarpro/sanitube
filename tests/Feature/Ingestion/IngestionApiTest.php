<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The ingestion endpoints, and what they must never say.
 *
 * The API is the boundary where a leak becomes permanent: a field published
 * once is a field a client depends on. So most of what follows is about
 * absence — no object key, no provider, no internal id, no path — rather than
 * about the happy response.
 */
final class IngestionApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-internal-api-token-for-testing';

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');

        config([
            'storage.default' => 'memory',
            'sanitube.api.internal_token' => self::TOKEN,
        ]);

        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // ------------------------------------------------------------ the guard

    #[Test]
    public function the_endpoints_refuse_a_caller_without_the_token(): void
    {
        $this->getJson('/api/v1/ingestion/batches')->assertUnauthorized();
        $this->getJson('/api/v1/ingestion/candidates')->assertUnauthorized();
        $this->postJson('/api/v1/ingestion/batches', [])->assertUnauthorized();
    }

    // ------------------------------------------------------------- starting

    #[Test]
    public function a_batch_can_be_started_from_a_prefix(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $this->store->put('library/b.wav', 'more audio');

        $response = $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::CloudImport->value,
            'prefix' => 'library',
        ]);

        // 202: the batch exists, the work does not. Answering 201 would imply
        // the import had happened.
        $response->assertAccepted()
            ->assertJsonPath('data.source', 'CLOUD_IMPORT')
            ->assertJsonPath('data.total', 2);
    }

    #[Test]
    public function starting_a_batch_never_carries_binary_payloads(): void
    {
        // Stated as a test because the obvious way to build this endpoint is
        // multipart upload, and 900 files will not survive one request.
        $this->store->put('inbox/session/take.wav', 'audio');

        $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::ManualUpload->value,
            'references' => ['inbox/session/take.wav'],
        ])->assertAccepted();

        Queue::assertPushed(ProcessIngestionItemJob::class);
    }

    #[Test]
    public function a_request_naming_neither_a_prefix_nor_references_is_refused(): void
    {
        $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::CloudImport->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('prefix');
    }

    #[Test]
    public function a_request_naming_both_is_refused_as_ambiguous(): void
    {
        $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::CloudImport->value,
            'prefix' => 'library',
            'references' => ['library/a.wav'],
        ])->assertUnprocessable();
    }

    #[Test]
    public function importing_from_a_managed_prefix_is_refused_with_a_reason(): void
    {
        $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::CloudImport->value,
            'prefix' => 'masters',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'UNSUPPORTED_SOURCE');
    }

    #[Test]
    public function a_source_that_is_not_implemented_is_refused_by_validation(): void
    {
        $this->authenticated()->postJson('/api/v1/ingestion/batches', [
            'source' => IngestionSource::Generated->value,
            'references' => ['whatever'],
        ])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    // -------------------------------------------------------------- reading

    #[Test]
    public function a_batch_reports_progress_computed_from_its_items(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->startAndRun('library');

        $this->authenticated()->getJson('/api/v1/ingestion/batches/'.$batch->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.IMPORTED', 1);
    }

    #[Test]
    public function candidates_are_listed_with_their_suggestion_named_as_one(): void
    {
        $this->store->put('library/02 - Night Bus.wav', 'audio');
        $this->startAndRun('library');

        $response = $this->authenticated()->getJson('/api/v1/ingestion/candidates')->assertOk();

        $response->assertJsonPath('data.0.suggested_title', 'Night Bus');
        $this->assertArrayNotHasKey('title', $response->json('data.0'));
    }

    #[Test]
    public function a_candidate_can_be_fetched_by_uuid(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $this->startAndRun('library');

        $candidate = TrackCandidate::query()->firstOrFail();

        $this->authenticated()->getJson('/api/v1/ingestion/candidates/'.$candidate->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $candidate->uuid);
    }

    // ---------------------------------------------------------- what leaks

    #[Test]
    public function no_response_carries_storage_vocabulary_or_an_internal_id(): void
    {
        // The single assertion that matters most here. A caller must not be
        // able to learn where a master lives, on which provider, under which
        // key — nor how many rows the platform holds.
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->startAndRun('library');
        $candidate = TrackCandidate::query()->firstOrFail();

        $responses = [
            $this->authenticated()->getJson('/api/v1/ingestion/batches'),
            $this->authenticated()->getJson('/api/v1/ingestion/batches/'.$batch->uuid),
            $this->authenticated()->getJson('/api/v1/ingestion/candidates'),
            $this->authenticated()->getJson('/api/v1/ingestion/candidates/'.$candidate->uuid),
        ];

        foreach ($responses as $response) {
            $raw = $response->content();

            // Values, checked against the whole body. These are the strings
            // that would actually let someone reach the bytes: where the
            // object lives, on which provider, under which key.
            foreach (['library/a.wav', 'memory', 'masters/', $candidate->asset->path] as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $raw,
                    sprintf('[%s] appears in an ingestion response.', $secret),
                );
            }

            // Field names, checked against the resource payload only. The
            // pagination envelope legitimately carries a `path` — the request
            // URL — and conflating that with a storage path would make this
            // test fail for the wrong reason.
            $data = $response->json('data');
            $this->assertIsArray($data);

            foreach ($this->keysIn($data) as $key) {
                $this->assertNotContains(
                    $key,
                    ['id', 'disk', 'path', 'bucket', 'provider', 'source_reference', 'ingestion_key', 'sha256'],
                    sprintf('[%s] is published by an ingestion resource.', $key),
                );
            }
        }
    }

    /**
     * Every key name appearing anywhere in a decoded payload.
     *
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

    #[Test]
    public function an_unknown_filter_is_a_422_rather_than_a_silently_unfiltered_list(): void
    {
        $this->authenticated()->getJson('/api/v1/ingestion/candidates?statuz=READY')->assertUnprocessable();
    }

    #[Test]
    public function candidates_can_be_filtered_by_status(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $this->startAndRun('library');

        // PENDING, not READY: a freshly imported candidate is awaiting the
        // technical analysis MED-001 performs. The filter is what is under
        // test here, so it is paired with a status that must return nothing.
        $this->authenticated()->getJson('/api/v1/ingestion/candidates?status=PENDING')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticated()->getJson('/api/v1/ingestion/candidates?status=PROMOTED')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --------------------------------------------------------------- helpers

    private function authenticated(): self
    {
        return $this->withHeader((string) config('sanitube.api.token_header', 'X-SaniTube-Token'), self::TOKEN);
    }

    private function startAndRun(string $prefix): IngestionBatch
    {
        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            prefix: $prefix,
        );

        $processor = $this->app->make(ProcessIngestionItem::class);

        foreach ($batch->items()->get() as $item) {
            $processor->handle($item);
        }

        $this->app->make(FinalizeIngestionBatch::class)->handle($batch->refresh());

        return $batch->refresh();
    }
}
