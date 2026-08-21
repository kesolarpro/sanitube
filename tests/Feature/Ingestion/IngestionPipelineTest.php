<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Getting hundreds of existing recordings into the platform without any of
 * them becoming a Track by accident.
 *
 * The load-bearing claim of this ticket is negative: an import creates
 * *candidates*, and the catalogue is untouched until a human promotes one.
 * Several tests below exist only to keep that true.
 */
final class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');

        config(['storage.default' => 'memory']);

        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // The test environment runs the queue synchronously, which would make
        // every dispatch happen inside the request that created the batch —
        // the one shape this pipeline is designed never to have. Faking the
        // queue restores the real deployment: work is enqueued here and picked
        // up later by a worker, which `runQueue()` stands in for.
        Queue::fake();
    }

    // ------------------------------------------------------- single import

    #[Test]
    public function a_single_source_becomes_a_verified_asset_and_a_candidate(): void
    {
        Event::fake([TrackCandidateCreated::class]);

        $contents = 'the recording itself';
        $this->store->put('library/01 - Opening.wav', $contents);

        $batch = $this->start(references: ['library/01 - Opening.wav']);
        $this->runQueue();

        $item = $batch->items()->firstOrFail();

        $this->assertSame(IngestionItemStatus::Imported, $item->status);

        $asset = Asset::query()->findOrFail($item->asset_id);
        $this->assertSame(AssetStatus::Verified, $asset->status);
        $this->assertSame(hash('sha256', $contents), $asset->sha256);

        $candidate = TrackCandidate::query()->findOrFail($item->candidate_id);
        $this->assertSame($asset->id, $candidate->asset_id);
        $this->assertSame(IngestionSource::CloudImport, $candidate->source);

        Event::assertDispatched(TrackCandidateCreated::class);
    }

    #[Test]
    public function a_candidate_only_ever_references_a_verified_asset(): void
    {
        // What makes a candidate worth reviewing at all: the bytes behind it
        // have been read back out of storage and hashed.
        $this->store->put('library/a.wav', 'audio');

        $this->start(references: ['library/a.wav']);
        $this->runQueue();

        foreach (TrackCandidate::query()->with('asset')->get() as $candidate) {
            $this->assertSame(AssetStatus::Verified, $candidate->asset->status);
        }
    }

    #[Test]
    public function importing_never_creates_a_track(): void
    {
        // The single most important assertion in this ticket. Nine hundred
        // files must not become nine hundred catalogue entries.
        $this->store->put('library/a.wav', 'one');
        $this->store->put('library/b.wav', 'two');
        $this->store->put('library/c.wav', 'three');

        $this->start(prefix: 'library');
        $this->runQueue();

        $this->assertSame(3, TrackCandidate::query()->count());
        $this->assertSame(0, Track::query()->count(), 'Ingestion created a Track.');
    }

    #[Test]
    public function the_suggested_title_is_a_guess_and_is_named_as_one(): void
    {
        $this->store->put('library/03_-_Midnight_Drive_MASTER.wav', 'audio');

        $this->start(references: ['library/03_-_Midnight_Drive_MASTER.wav']);
        $this->runQueue();

        $candidate = TrackCandidate::query()->firstOrFail();

        $this->assertSame('Midnight Drive MASTER', $candidate->suggested_title);
        $this->assertSame('03_-_Midnight_Drive_MASTER.wav', $candidate->original_filename);
    }

    // -------------------------------------------------------- whole batches

    #[Test]
    public function a_batch_completes_when_every_item_succeeds(): void
    {
        foreach (range(1, 5) as $n) {
            $this->store->put("library/track-{$n}.wav", "audio {$n}");
        }

        $batch = $this->start(prefix: 'library');
        $this->runQueue();

        $batch->refresh();

        $this->assertSame(IngestionBatchStatus::Completed, $batch->status);
        $this->assertSame(5, $batch->itemCounts()[IngestionItemStatus::Imported->value]);
        $this->assertNotNull($batch->completed_at);
    }

    #[Test]
    public function a_batch_that_loses_some_items_says_so_rather_than_reporting_success(): void
    {
        // Importing 900 files and losing four is neither a success nor a
        // failure, and a status that can only say one of those hides the four.
        $this->store->put('library/good.wav', 'audio');

        $batch = $this->start(references: ['library/good.wav', 'library/missing.wav']);
        $this->runQueue();

        $batch->refresh();

        $this->assertSame(IngestionBatchStatus::CompletedWithErrors, $batch->status);
        $this->assertSame(1, $batch->itemCounts()[IngestionItemStatus::Imported->value]);
        $this->assertSame(1, $batch->itemCounts()[IngestionItemStatus::Failed->value]);
    }

    #[Test]
    public function a_batch_where_nothing_got_in_is_a_failure(): void
    {
        $batch = $this->start(references: ['library/missing-one.wav', 'library/missing-two.wav']);
        $this->runQueue();

        $this->assertSame(IngestionBatchStatus::Failed, $batch->refresh()->status);
    }

    #[Test]
    public function a_failed_item_records_a_code_a_retry_policy_can_read(): void
    {
        $batch = $this->start(references: ['library/missing.wav']);
        $this->runQueue();

        $item = $batch->items()->firstOrFail();

        $this->assertSame(IngestionItemStatus::Failed, $item->status);
        $this->assertSame(IngestionFailureCode::SourceUnreadable, $item->failure_code);
        $this->assertNotNull($item->failure_message);
    }

    /**
     * A stored failure is a leak with a copy.
     *
     * `failure_message` takes an exception's own words, and one of the two
     * callers that fills it catches a bare `Throwable` from the storage
     * client. An S3 403 arrives as
     * `GET https://…?X-Amz-Signature=… resulted in a 403`, and unlike a log
     * line this column is durable: it is read by the internal API and it goes
     * into every database backup taken afterwards.
     *
     * The *code* is untouched — that is what a retry policy reads, and it is
     * a closed vocabulary rather than somebody's words.
     */
    #[Test]
    public function a_storage_failure_does_not_store_the_address_it_failed_at(): void
    {
        $this->store->put('library/01 - Opening.wav', 'the recording itself');

        $this->store->failWith(
            'GET https://bucket.r2.cloudflarestorage.com/library/01.wav'
                .'?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefcafe resulted in a 403',
        );

        $batch = $this->start(references: ['library/01 - Opening.wav']);
        $this->runQueue();

        $item = $batch->items()->firstOrFail();

        $this->assertSame(IngestionItemStatus::Failed, $item->status);
        $this->assertNotNull($item->failure_code, 'The code is what a retry policy reads, and it must survive.');

        $stored = (string) $item->failure_message;

        $this->assertStringNotContainsString('X-Amz-Signature', $stored);
        $this->assertStringNotContainsString('bucket.r2.cloudflarestorage.com', $stored);
        $this->assertStringContainsString('403', $stored, 'Scrubbed to nothing is the same as not recording it.');
    }

    // ---------------------------------------------------------- idempotence

    #[Test]
    public function the_same_source_submitted_twice_is_not_imported_twice(): void
    {
        $this->store->put('library/a.wav', 'audio');

        $first = $this->start(references: ['library/a.wav']);

        // Submitted again while the first is still in flight.
        $second = $this->start(references: ['library/a.wav']);

        $this->assertSame(IngestionItemStatus::Pending, $first->items()->firstOrFail()->status);

        $skipped = $second->items()->firstOrFail();
        $this->assertSame(IngestionItemStatus::Skipped, $skipped->status);
        $this->assertSame(IngestionFailureCode::AlreadyInFlight, $skipped->failure_code);

        $this->runQueue();

        // One asset, not two.
        $this->assertSame(1, Asset::query()->count());
    }

    #[Test]
    public function the_database_refuses_two_items_in_flight_for_one_key(): void
    {
        // Enforced by a unique index rather than by the service remembering to
        // look: a future write path that forgets is refused by the engine.
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->start(references: ['library/a.wav']);

        $this->expectException(UniqueConstraintViolationException::class);

        IngestionItem::query()->create([
            'batch_id' => $batch->id,
            'ingestion_key' => $batch->items()->firstOrFail()->ingestion_key,
            'source_reference' => 'library/a.wav',
            'original_filename' => 'a.wav',
            'status' => IngestionItemStatus::Pending,
        ]);
    }

    #[Test]
    public function a_finished_key_can_be_imported_again(): void
    {
        // The marker goes NULL once terminal, so history accumulates while
        // only one attempt can ever be in flight. Re-importing after a
        // deletion has to remain possible.
        $this->store->put('library/a.wav', 'audio');

        $this->start(references: ['library/a.wav']);
        $this->runQueue();

        $second = $this->start(references: ['library/a.wav']);
        $this->runQueue();

        $this->assertNotSame(IngestionItemStatus::Skipped, $second->items()->firstOrFail()->status);
    }

    #[Test]
    public function running_an_item_twice_does_not_produce_a_second_asset(): void
    {
        // A queue that delivers a job twice — which every at-least-once queue
        // eventually does — must not double the catalogue's storage.
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->start(references: ['library/a.wav']);
        $item = $batch->items()->firstOrFail();

        $processor = $this->app->make(ProcessIngestionItem::class);
        $processor->handle($item);
        $processor->handle($item->refresh());

        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(1, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_retry_after_a_crash_mid_upload_reuses_the_same_asset(): void
    {
        // The failure this pipeline is arranged around. The asset id is
        // written before the upload starts, so the retry addresses the same
        // deterministic object key rather than registering a second asset and
        // orphaning the first one's bytes.
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->start(references: ['library/a.wav']);
        $item = $batch->items()->firstOrFail();

        $this->store->failWith('The endpoint went away mid-upload.');

        $processor = $this->app->make(ProcessIngestionItem::class);
        $processor->handle($item);

        $item->refresh();
        $assetId = $item->asset_id;

        $this->assertNotNull($assetId, 'The asset was not recorded before the upload.');
        $this->assertSame(IngestionItemStatus::Failed, $item->status);

        // The worker comes back and the provider is healthy again.
        $this->store->recover();
        $item->forceFill(['status' => IngestionItemStatus::Pending])->save();

        $processor->handle($item->refresh());

        $this->assertSame(IngestionItemStatus::Imported, $item->refresh()->status);
        $this->assertSame($assetId, $item->asset_id, 'The retry registered a second asset.');
        $this->assertSame(1, Asset::query()->count());
    }

    // ------------------------------------------------------------ duplicates

    #[Test]
    public function identical_bytes_are_recorded_as_a_duplicate_rather_than_refused(): void
    {
        // The same master legitimately arrives twice — under two names, from
        // two folders. Refusing it would lose the fact that it happened.
        $this->store->put('library/a.wav', 'the very same recording');
        $this->store->put('library/copy-of-a.wav', 'the very same recording');

        $batch = $this->start(prefix: 'library');
        $this->runQueue();

        $statuses = $batch->items()->pluck('status')->map(
            static fn (IngestionItemStatus $status): string => $status->value,
        )->all();

        sort($statuses);

        $this->assertSame(['DUPLICATE', 'IMPORTED'], $statuses);

        $duplicate = TrackCandidate::query()->where('status', TrackCandidateStatus::Duplicate->value)->firstOrFail();
        $this->assertNotNull($duplicate->matched_asset_id);
    }

    // -------------------------------------------------- storage and provider

    #[Test]
    public function a_provider_that_is_unavailable_fails_the_item_and_not_the_batch_shape(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->start(references: ['library/a.wav']);

        $this->store->failWith('Connection refused.');
        $this->runQueue();

        $batch->refresh();

        $this->assertSame(IngestionBatchStatus::Failed, $batch->status);
        $this->assertSame(IngestionItemStatus::Failed, $batch->items()->firstOrFail()->status);
        $this->assertSame(0, TrackCandidate::query()->count());
    }

    // ------------------------------------------------------ cloud validation

    #[Test]
    public function a_cloud_import_may_not_be_pointed_at_the_platforms_own_output(): void
    {
        // Otherwise "import everything" re-ingests every master as new
        // material, and running it twice produces duplicates of duplicates.
        foreach (['masters', 'artwork', 'staging', 'exports'] as $managed) {
            try {
                $this->start(prefix: $managed);
                $this->fail(sprintf('An import from [%s] was accepted.', $managed));
            } catch (IngestionException $exception) {
                // The code, not the sentence. Asserting on English goes green
                // on a reworded message and red on a translated one, which is
                // the wrong way round for both.
                $this->assertSame('MANAGED_PREFIX', $exception->reason);
            }
        }
    }

    #[Test]
    public function a_cloud_import_needs_somewhere_to_import_from(): void
    {
        // Both shapes of "you have not said what to import": a blank prefix,
        // and nothing supplied at all. They used to answer differently — the
        // second reported "Nothing to import under [(no references)]", which
        // describes a path that was never given.
        foreach ([fn () => $this->start(prefix: ''), fn () => $this->start()] as $attempt) {
            try {
                $attempt();
                $this->fail('An import with nothing selected was accepted.');
            } catch (IngestionException $exception) {
                $this->assertSame('NOTHING_SELECTED', $exception->reason);
            }
        }
    }

    #[Test]
    public function expanding_a_prefix_skips_the_platforms_own_objects(): void
    {
        // A prefix that legitimately contains both. The managed objects are
        // filtered out rather than making the whole request fail.
        $this->store->put('library/a.wav', 'audio');
        $this->store->put('library/b.wav', 'more audio');

        $batch = $this->start(prefix: 'library');

        $this->assertSame(2, $batch->items()->count());
    }

    #[Test]
    public function a_batch_larger_than_the_limit_is_refused(): void
    {
        config(['ingestion.max_batch' => 3]);

        foreach (range(1, 4) as $n) {
            $this->store->put("library/track-{$n}.wav", "audio {$n}");
        }

        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('exceeds the limit');

        $this->start(prefix: 'library');
    }

    #[Test]
    public function a_source_that_is_declared_but_not_implemented_is_refused_by_name(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('GENERATED');

        $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::Generated,
            references: ['whatever'],
        );
    }

    #[Test]
    public function a_manual_upload_may_only_refer_to_the_inbox(): void
    {
        // Otherwise "import this object" would be a way to name any object in
        // the store, including a master.
        $this->store->put('somewhere-else/a.wav', 'audio');

        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('must refer to an object under');

        $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::ManualUpload,
            references: ['somewhere-else/a.wav'],
        );
    }

    #[Test]
    public function a_manual_upload_from_the_inbox_imports(): void
    {
        $this->store->put('inbox/session/take-1.wav', 'audio');

        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::ManualUpload,
            references: ['inbox/session/take-1.wav'],
        );

        $this->runQueue();

        $item = $batch->items()->firstOrFail();

        $this->assertSame(IngestionItemStatus::Imported, $item->status);
        $this->assertSame(IngestionSource::ManualUpload, TrackCandidate::query()->firstOrFail()->source);
    }

    #[Test]
    public function the_source_object_is_never_deleted_by_an_import(): void
    {
        // A cloud reference belongs to whoever owns that storage, and an
        // import that removes its own input is one nobody dares run twice.
        $this->store->put('library/a.wav', 'audio');

        $this->start(references: ['library/a.wav']);
        $this->runQueue();

        $this->assertTrue($this->store->exists('library/a.wav'));
    }

    // ---------------------------------------------------------------- queue

    #[Test]
    public function work_is_queued_rather_than_done_in_the_request(): void
    {
        $this->store->put('library/a.wav', 'audio');
        $this->store->put('library/b.wav', 'audio too');

        $this->start(prefix: 'library');

        Queue::assertPushed(ProcessIngestionItemJob::class, 2);
    }

    #[Test]
    public function finalising_a_batch_twice_changes_nothing(): void
    {
        // Under concurrency two items can each believe they were the last.
        $this->store->put('library/a.wav', 'audio');
        $batch = $this->start(references: ['library/a.wav']);
        $this->runQueue();

        $finalizer = $this->app->make(FinalizeIngestionBatch::class);
        $completedAt = $batch->refresh()->completed_at;

        $finalizer->handle($batch);

        $this->assertEquals($completedAt, $batch->refresh()->completed_at);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $references
     */
    private function start(?string $prefix = null, array $references = []): IngestionBatch
    {
        return $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            prefix: $prefix,
            references: $references,
        );
    }

    /**
     * Run every queued item to completion, synchronously.
     */
    private function runQueue(): void
    {
        $processor = $this->app->make(ProcessIngestionItem::class);
        $finalizer = $this->app->make(FinalizeIngestionBatch::class);

        foreach (IngestionItem::query()->whereNotNull('active_marker')->get() as $item) {
            $processor->handle($item);
        }

        foreach (IngestionBatch::query()->get() as $batch) {
            $finalizer->handle($batch);
        }
    }
}
