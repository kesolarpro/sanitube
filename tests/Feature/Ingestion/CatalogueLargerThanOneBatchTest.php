<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Services\IngestionKey;
use SaniTube\Ingestion\Services\PlanBulkImport;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * BULK-003. Importing a catalogue that does not fit in one batch.
 *
 * A batch is capped, deliberately: it is a unit of work a person can inspect
 * and, when it goes wrong, reason about. Eight batches of five hundred that
 * half-failed are diagnosable; one batch of four thousand is a wall of rows.
 *
 * **What was missing was the second batch.** `--prefix` takes everything under
 * a folder, and a selection over the cap is refused outright — so a four
 * thousand file catalogue could be listed, refused, and imported no further
 * without naming three and a half thousand object keys by hand. The documented
 * remedy, "two batches of five hundred", was not reachable from any interface.
 *
 * The answer is the same command run again. What has already been dealt with is
 * read from the items table rather than from a cursor an operator has to keep,
 * so the sequence survives a closed terminal, a crashed worker and a reboot —
 * which is the only kind of resumability worth having for an import that takes
 * hours.
 */
final class CatalogueLargerThanOneBatchTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // ------------------------------------------------------------ the walk

    /**
     * The whole point, stated as one test: a catalogue that needs four runs.
     */
    #[Test]
    public function a_catalogue_larger_than_one_batch_is_imported_by_running_the_command_again(): void
    {
        config(['ingestion.max_batch' => 5]);

        $this->library(18);

        $imported = 0;

        for ($run = 1; $run <= 4; $run++) {
            $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])
                ->assertSuccessful();

            // Each run leaves its items terminal, as a finished batch would.
            $imported += $this->completeEverythingPending();
        }

        $this->assertSame(18, $imported);
        $this->assertSame(4, IngestionBatch::query()->count());

        // Every object, exactly once. Not "about eighteen" — a resumable
        // import that overlaps is one that pays twice for the same egress.
        $this->assertSame(18, IngestionItem::query()->count());
        $this->assertSame(18, IngestionItem::query()->distinct()->count('ingestion_key'));
    }

    /**
     * And it still partitions the catalogue when the store re-orders its
     * listing between runs.
     *
     * Which S3-compatible services are free to do: lexicographic order is
     * guaranteed per page, not across a re-listing where objects have been
     * added or removed. Resumption does not depend on the order because what
     * is left is read from the items table rather than from a position — and
     * an order that matters is an order that eventually betrays you.
     */
    #[Test]
    public function the_walk_survives_a_store_that_lists_in_a_different_order_each_time(): void
    {
        config(['ingestion.max_batch' => 4]);

        $this->store->listUnsorted();
        $this->library(10);

        for ($run = 1; $run <= 3; $run++) {
            $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])
                ->assertSuccessful();

            $this->completeEverythingPending();

            // Re-writing an object moves it in an insertion-ordered listing,
            // so the next run sees a different sequence than this one did.
            $this->store->put(sprintf('library/%04d.wav', $run), 'audio-'.$run);
        }

        $this->assertSame(10, IngestionItem::query()->count());
        $this->assertSame(10, IngestionItem::query()->distinct()->count('ingestion_key'));
    }

    /**
     * And a fifth run, after the catalogue is in, does nothing at all.
     */
    #[Test]
    public function running_it_again_after_everything_is_imported_does_nothing(): void
    {
        config(['ingestion.max_batch' => 10]);

        $this->library(3);

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])->assertSuccessful();
        $this->completeEverythingPending();

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])
            ->expectsOutputToContain('already been imported')
            ->assertSuccessful();

        $this->assertSame(1, IngestionBatch::query()->count());
        $this->assertSame(3, IngestionItem::query()->count());
    }

    /**
     * Without the flag, the refusal stands.
     *
     * Silently importing a subset of what somebody named would be the worse
     * default: an operator who asked for a folder and got five hundred of it,
     * with no error, has an import they believe is finished.
     */
    #[Test]
    public function a_selection_over_the_cap_is_still_refused_when_continuation_was_not_asked_for(): void
    {
        config(['ingestion.max_batch' => 5]);

        $this->library(9);

        $this->artisan('sanitube:import', ['--prefix' => 'library'])->assertFailed();

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    // -------------------------------------------------- what counts as done

    /**
     * A failure is exactly what a second run should pick up.
     *
     * Treating it as handled would mean the only way to retry one file out of
     * four thousand was to find it by hand, which at that scale is the same as
     * never.
     */
    #[Test]
    public function a_failed_item_is_retried_by_the_next_run(): void
    {
        config(['ingestion.max_batch' => 10]);

        $this->library(2);

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])->assertSuccessful();

        $items = IngestionItem::query()->orderBy('id')->get();
        $items[0]->forceFill(['status' => IngestionItemStatus::Imported, 'active_marker' => null])->save();
        $items[1]->forceFill(['status' => IngestionItemStatus::Failed, 'active_marker' => null])->save();

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])->assertSuccessful();

        // One new item: the failure, and only the failure.
        $this->assertSame(3, IngestionItem::query()->count());
        $this->assertSame(
            $items[1]->source_reference,
            (string) IngestionItem::query()->orderByDesc('id')->value('source_reference'),
        );
    }

    /**
     * Skipped means the work was never attempted, so it is not done either.
     */
    #[Test]
    public function a_skipped_item_does_not_make_a_reference_handled(): void
    {
        $this->library(1);

        $reference = 'library/0001.wav';

        IngestionItem::query()->create([
            'batch_id' => IngestionBatch::query()->create([
                'source' => IngestionSource::CloudImport,
                'status' => IngestionBatchStatus::Completed,
            ])->id,
            'ingestion_key' => IngestionKey::for(IngestionSource::CloudImport, $reference),
            'source_reference' => $reference,
            'original_filename' => '0001.wav',
            'status' => IngestionItemStatus::Skipped,
        ]);

        $plan = $this->app->make(PlanBulkImport::class)
            ->for(IngestionSource::CloudImport, [$reference], 10);

        $this->assertSame(0, $plan->alreadyHandled);
        $this->assertSame([$reference], $plan->references);
    }

    /**
     * Everything else is. An item in review is one somebody is looking at, and
     * fetching its bytes again would cost real egress to learn what the
     * database already knows.
     */
    #[Test]
    public function an_item_awaiting_review_or_found_duplicate_counts_as_handled(): void
    {
        foreach ([IngestionItemStatus::NeedsReview, IngestionItemStatus::Duplicate, IngestionItemStatus::Imported] as $index => $status) {
            $reference = sprintf('library/handled-%d.wav', $index);

            IngestionItem::query()->create([
                'batch_id' => IngestionBatch::query()->create([
                    'source' => IngestionSource::CloudImport,
                    'status' => IngestionBatchStatus::Completed,
                ])->id,
                'ingestion_key' => IngestionKey::for(IngestionSource::CloudImport, $reference),
                'source_reference' => $reference,
                'original_filename' => basename($reference),
                'status' => $status,
            ]);

            $plan = $this->app->make(PlanBulkImport::class)
                ->for(IngestionSource::CloudImport, [$reference], 10);

            $this->assertSame(1, $plan->alreadyHandled, sprintf('[%s] was offered for import again.', $status->value));
            $this->assertTrue($plan->isComplete());
        }
    }

    // ----------------------------------------------------------- the report

    /**
     * The numbers an operator needs to decide whether to run it again.
     */
    #[Test]
    public function it_reports_what_is_left(): void
    {
        config(['ingestion.max_batch' => 5]);

        $this->library(12);

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true])
            ->expectsOutputToContain('7 still to import')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_reports_the_plan_and_queues_nothing(): void
    {
        config(['ingestion.max_batch' => 5]);

        $this->library(12);

        $this->artisan('sanitube:import', ['--prefix' => 'library', '--continue' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, IngestionBatch::query()->count());
        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------- the manifest

    /**
     * A large manifest is walked the same way — and each batch keeps its
     * evidence.
     *
     * Narrowing to a bare reference list would lose every title, artist and
     * ISRC the operator wrote down, which for rows two hundred to four hundred
     * is the entire reason they wrote a manifest.
     */
    #[Test]
    public function a_manifest_larger_than_one_batch_is_walked_without_losing_its_evidence(): void
    {
        config(['ingestion.max_batch' => 2]);

        $this->library(5);

        $lines = ['reference,title'];

        for ($index = 1; $index <= 5; $index++) {
            $lines[] = sprintf('library/%04d.wav,Title %d', $index, $index);
        }

        $path = tempnam(sys_get_temp_dir(), 'sanitube-manifest-').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        try {
            $this->artisan('sanitube:import', ['--manifest' => $path, '--continue' => true])
                ->expectsOutputToContain('3 still to import')
                ->assertSuccessful();
        } finally {
            unlink($path);
        }

        $this->assertSame(2, IngestionItem::query()->count());

        foreach (IngestionItem::query()->get() as $item) {
            $this->assertNotNull(
                $item->manifest_metadata,
                'A narrowed manifest lost the evidence the operator wrote down.',
            );
        }
    }

    // ------------------------------------------------------------- fixtures

    /**
     * Objects under `library/`, in a deliberately unhelpful listing order.
     */
    private function library(int $count): void
    {
        for ($index = $count; $index >= 1; $index--) {
            $this->store->put(sprintf('library/%04d.wav', $index), 'audio-'.$index);
        }
    }

    /**
     * Mark everything the last batch queued as finished, as a worker would.
     */
    private function completeEverythingPending(): int
    {
        $pending = IngestionItem::query()
            ->where('status', IngestionItemStatus::Pending->value)
            ->get();

        foreach ($pending as $item) {
            $item->forceFill([
                'status' => IngestionItemStatus::Imported,
                'active_marker' => null,
            ])->save();
        }

        return $pending->count();
    }
}
