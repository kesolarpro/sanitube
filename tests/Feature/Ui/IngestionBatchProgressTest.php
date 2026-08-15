<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ui\Queries\IngestionBatchProgressQuery;
use Tests\TestCase;

/**
 * Batch progress, derived from the items rather than stored beside them.
 *
 * The whole value of this read model is that it cannot drift. A counter column
 * updated as each item finishes is faster to read and wrong the first time a
 * worker dies mid-item — and a progress screen that disagrees with the item
 * list is one an operator stops believing.
 */
final class IngestionBatchProgressTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_counts_every_item_state_including_the_empty_ones(): void
    {
        // "No failures" and "the failed bucket fell out of the query" call for
        // opposite reactions from somebody watching nine hundred files import.
        $batch = $this->batch();

        $this->item($batch, IngestionItemStatus::Imported);
        $this->item($batch, IngestionItemStatus::Imported);
        $this->item($batch, IngestionItemStatus::Failed);

        $counts = app(IngestionBatchProgressQuery::class)->forBatch($batch)['items'];

        foreach (IngestionItemStatus::cases() as $case) {
            $this->assertArrayHasKey($case->value, $counts, sprintf('%s vanished from the counts.', $case->value));
            $this->assertIsInt($counts[$case->value]);
        }

        $this->assertSame(2, $counts[IngestionItemStatus::Imported->value]);
        $this->assertSame(1, $counts[IngestionItemStatus::Failed->value]);
        $this->assertSame(0, $counts[IngestionItemStatus::Duplicate->value]);
        $this->assertSame(3, $counts['total']);
    }

    #[Test]
    public function a_partial_failure_leaves_the_successes_counted(): void
    {
        // The shape this exists for: 897 imported, 3 failed. Nothing about the
        // three may erase the 897.
        $batch = $this->batch();

        for ($index = 0; $index < 20; $index++) {
            $this->item($batch, IngestionItemStatus::Imported);
        }

        $this->item($batch, IngestionItemStatus::Failed);
        $this->item($batch, IngestionItemStatus::Failed);

        $counts = app(IngestionBatchProgressQuery::class)->forBatch($batch)['items'];

        $this->assertSame(20, $counts[IngestionItemStatus::Imported->value]);
        $this->assertSame(2, $counts[IngestionItemStatus::Failed->value]);
        $this->assertSame(22, $counts['total']);
    }

    #[Test]
    public function progress_follows_the_items_and_cannot_drift_from_them(): void
    {
        // Derived, not stored. Changing an item's state changes the progress
        // with nothing else being told to update.
        $batch = $this->batch();
        $item = $this->item($batch, IngestionItemStatus::Processing);

        $query = app(IngestionBatchProgressQuery::class);

        $this->assertSame(1, $query->forBatch($batch)['items'][IngestionItemStatus::Processing->value]);

        DB::table('ingestion_items')->where('id', $item)->update(['status' => IngestionItemStatus::Imported->value]);

        $after = $query->forBatch($batch->fresh())['items'];

        $this->assertSame(0, $after[IngestionItemStatus::Processing->value]);
        $this->assertSame(1, $after[IngestionItemStatus::Imported->value]);
    }

    #[Test]
    public function one_batch_never_counts_another_batchs_items(): void
    {
        $mine = $this->batch();
        $theirs = $this->batch();

        $this->item($mine, IngestionItemStatus::Imported);
        $this->item($theirs, IngestionItemStatus::Imported);
        $this->item($theirs, IngestionItemStatus::Imported);

        $query = app(IngestionBatchProgressQuery::class);

        $this->assertSame(1, $query->forBatch($mine)['items']['total']);
        $this->assertSame(2, $query->forBatch($theirs)['items']['total']);
    }

    #[Test]
    public function counting_a_large_batch_costs_one_query(): void
    {
        // A count per item would be nine hundred round trips on the batch this
        // platform exists to import.
        $batch = $this->batch();

        for ($index = 0; $index < 40; $index++) {
            $this->item($batch, IngestionItemStatus::Imported);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(IngestionBatchProgressQuery::class)->forBatch($batch);

        $this->assertSame(1, $queries, sprintf('Counting the batch took %d queries.', $queries));
    }

    #[Test]
    public function no_storage_location_or_internal_key_reaches_the_payload(): void
    {
        $batch = $this->batch();
        $this->item($batch, IngestionItemStatus::Imported);

        $encoded = (string) json_encode(app(IngestionBatchProgressQuery::class)->forBatch($batch));

        foreach (['"id"', '"batch_id"', '"asset_id"', 'source_reference', 'original_filename'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, sprintf('%s reached the browser.', $forbidden));
        }

        $this->assertStringContainsString($batch->uuid, $encoded);
    }

    // --------------------------------------------------------------- helpers

    private function batch(): IngestionBatch
    {
        return IngestionBatch::query()->create([
            'source' => IngestionSource::CloudImport,
            'status' => IngestionBatchStatus::Processing,
        ]);
    }

    private function item(IngestionBatch $batch, IngestionItemStatus $status): int
    {
        return (int) DB::table('ingestion_items')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'batch_id' => $batch->getKey(),
            'ingestion_key' => substr(hash('sha256', (string) Str::uuid7()), 0, 64),
            'source_reference' => 'imports/'.Str::uuid7().'.wav',
            'original_filename' => 'take.wav',
            'status' => $status->value,
            'active_marker' => null,
            'attempt_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
