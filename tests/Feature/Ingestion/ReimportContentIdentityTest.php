<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Models\Asset;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
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
 * What an object key means, and what it costs to re-import.
 *
 * **BULK-001c, measured.** The finding this suite exists to pin down is that
 * SaniTube identifies imported content by its *bytes* and never by its source
 * key — which is correct, and which currently costs a second stored copy of
 * every re-imported master.
 *
 * The four cases the ticket names, and what each one must do:
 *
 * | | source key | bytes | required behaviour |
 * |---|---|---|---|
 * | **A** | same | same | recognised as already held; provenance recorded |
 * | **B** | same | **changed** | ingested as new content — never skipped |
 * | **C** | different | same | recognised as already held |
 * | **D** | different | different | ordinary import |
 *
 * **B is the one that makes the shortcut forbidden.** "Same object key means
 * unchanged" would skip a master somebody replaced under the same name, and
 * the platform would keep serving the old audio while everyone involved
 * believed the new take had been imported. Nothing here may treat a key as an
 * identity, and `case_b` fails the moment something does.
 *
 * The amplification measurement is deliberately an assertion rather than a
 * comment. It is the baseline the amendment in ADR-0015 has to move, and a
 * number in a docblock is a number nobody notices going stale.
 */
final class ReimportContentIdentityTest extends TestCase
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

    // ------------------------------------------------- the four identities

    #[Test]
    public function case_a_the_same_key_holding_the_same_bytes_is_recognised_as_already_held(): void
    {
        $this->seedSource('library/master.wav', 'the very same master');
        $this->import('library/master.wav');

        // A second batch naming the same object. The ingestion key differs
        // because the batch does, so this is a genuine second import rather
        // than a retry of the first.
        $this->import('library/master.wav');

        $candidates = TrackCandidate::query()->orderBy('id')->get();

        $this->assertCount(2, $candidates, 'A re-import must still produce a reviewable proposal.');
        $this->assertSame(TrackCandidateStatus::Pending, $candidates[0]->status);
        $this->assertSame(
            TrackCandidateStatus::Duplicate,
            $candidates[1]->status,
            'The second import of identical bytes was not recognised as already held.',
        );

        // Provenance, both directions: the second names the first, the first
        // names nobody.
        $assets = Asset::query()->orderBy('id')->get();

        $this->assertSame($assets[0]->id, $assets[1]->duplicate_of_asset_id);
        $this->assertNull($assets[0]->duplicate_of_asset_id);
        $this->assertSame($assets[0]->sha256, $assets[1]->sha256);
    }

    #[Test]
    public function case_b_the_same_key_holding_changed_bytes_is_ingested_rather_than_skipped(): void
    {
        // The case that forbids the shortcut. An object key is a name, and a
        // name can be pointed at different content tomorrow — by an operator
        // re-exporting a master and uploading it over the old one, which is
        // the ordinary way a corrected take arrives.
        $this->seedSource('library/master.wav', 'the first take');
        $this->import('library/master.wav');

        $this->seedSource('library/master.wav', 'the corrected take, longer and different');
        $this->import('library/master.wav');

        $assets = Asset::query()->orderBy('id')->get();

        $this->assertCount(2, $assets);
        $this->assertNotSame($assets[0]->sha256, $assets[1]->sha256);
        $this->assertNull(
            $assets[1]->duplicate_of_asset_id,
            'Changed bytes under a reused key were treated as a duplicate. A replaced master would be lost.',
        );

        $candidates = TrackCandidate::query()->orderBy('id')->get();

        $this->assertSame(
            TrackCandidateStatus::Pending,
            $candidates[1]->status,
            'A replaced master must reach review as new content, not as a duplicate to dismiss.',
        );

        // And the new bytes are what is held, not the old ones.
        $this->assertSame(
            hash('sha256', 'the corrected take, longer and different'),
            $assets[1]->sha256,
        );
    }

    #[Test]
    public function case_c_a_different_key_holding_the_same_bytes_is_recognised_as_already_held(): void
    {
        $this->seedSource('library/one.wav', 'identical audio');
        $this->import('library/one.wav');

        $this->seedSource('archive/another-name.wav', 'identical audio');
        $this->import('archive/another-name.wav');

        $assets = Asset::query()->orderBy('id')->get();

        $this->assertSame($assets[0]->id, $assets[1]->duplicate_of_asset_id);

        // The filename each arrived under is kept. It is the only thing that
        // distinguishes them, and a reviewer deciding which to keep needs it.
        $candidates = TrackCandidate::query()->orderBy('id')->get();

        $this->assertSame('one.wav', $candidates[0]->original_filename);
        $this->assertSame('another-name.wav', $candidates[1]->original_filename);
    }

    #[Test]
    public function case_d_different_keys_holding_different_bytes_are_two_ordinary_imports(): void
    {
        $this->seedSource('library/one.wav', 'first');
        $this->seedSource('library/two.wav', 'second');

        $this->import('library/one.wav', 'library/two.wav');

        $assets = Asset::query()->orderBy('id')->get();

        $this->assertCount(2, $assets);
        $this->assertNull($assets[0]->duplicate_of_asset_id);
        $this->assertNull($assets[1]->duplicate_of_asset_id);
    }

    // --------------------------------------------------------- retry safety

    #[Test]
    public function retrying_an_item_neither_re_imports_nor_duplicates(): void
    {
        $this->seedSource('library/master.wav', 'audio');

        $batch = $this->import('library/master.wav');

        $item = IngestionItem::query()->where('batch_id', $batch->id)->firstOrFail();
        $assetId = $item->asset_id;

        // The job running twice — a worker that was killed after its work
        // landed, and whose message came back.
        $this->app->make(ProcessIngestionItem::class)->handle($item->refresh());

        $this->assertSame(1, Asset::query()->count(), 'A retry created a second asset.');
        $this->assertSame(1, TrackCandidate::query()->count(), 'A retry created a second candidate.');
        $this->assertSame($assetId, $item->refresh()->asset_id);
        $this->assertSame(IngestionItemStatus::Imported, $item->refresh()->status);
    }

    #[Test]
    public function retrying_a_whole_batch_is_free(): void
    {
        $this->seedSource('library/a.wav', 'one');
        $this->seedSource('library/b.wav', 'two');

        $batch = $this->import('library/a.wav', 'library/b.wav');

        $processor = $this->app->make(ProcessIngestionItem::class);

        foreach (IngestionItem::query()->where('batch_id', $batch->id)->get() as $item) {
            $processor->handle($item);
        }

        $this->assertSame(2, Asset::query()->count());
        $this->assertSame(2, TrackCandidate::query()->count());
        $this->assertSame(2, count($this->canonicalKeys()));
    }

    // ------------------------------------------------------ the measurement

    #[Test]
    public function identical_bytes_are_currently_stored_twice_and_this_is_the_baseline(): void
    {
        // **The BULK-001c finding, pinned to a number.**
        //
        // Content identity is already correct: the platform knows these are
        // the same bytes and says so. What it does *not* do is stop storing
        // them again — `AssetStorageService` finds the duplicate and then
        // moves the staged object into the second asset's canonical key
        // anyway, because AST-001 gives every asset an object key derived
        // from its own UUID and nothing may leave an asset without bytes.
        //
        // Changing that is a domain amendment, not a refactor: it alters an
        // AST-001 invariant and contradicts a merged assertion in
        // AssetStorageWorkflowTest. It is written up in ADR-0015 and marked
        // REVIEW_REQUIRED rather than performed here.
        //
        // This test is the baseline that amendment has to move. It asserts
        // today's behaviour deliberately, so that the change shows up as this
        // test failing rather than as nobody noticing.
        $contents = 'a master of some length, stored twice today';

        $this->seedSource('library/one.wav', $contents);
        $this->import('library/one.wav');

        $this->seedSource('archive/two.wav', $contents);
        $this->import('archive/two.wav');

        $keys = $this->canonicalKeys();

        $this->assertCount(2, $keys, 'Two canonical objects — the amplification BULK-001c is about.');

        $stored = 0;

        foreach ($keys as $key) {
            $stored += $this->store->size($key);
        }

        $this->assertSame(
            strlen($contents) * 2,
            $stored,
            'Identical content occupies exactly twice its own size. Amplification factor: 2.',
        );
    }

    #[Test]
    public function nothing_is_left_in_staging_after_a_run(): void
    {
        // Whatever the amendment does with canonical objects, staging must
        // stay transient — a second copy that is never cleaned up would be a
        // worse amplification than the one being measured.
        $this->seedSource('library/one.wav', 'audio');
        $this->import('library/one.wav');

        foreach ($this->store->keys() as $key) {
            $this->assertStringNotContainsString('staging', $key, sprintf('[%s] was left in staging.', $key));
        }
    }

    // ------------------------------------------------------------ fixtures

    private function seedSource(string $key, string $contents): void
    {
        $this->store->put($key, $contents);
    }

    private function import(string ...$references): IngestionBatch
    {
        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            prefix: null,
            references: array_values($references),
        );

        $processor = $this->app->make(ProcessIngestionItem::class);

        foreach (IngestionItem::query()->where('batch_id', $batch->id)->get() as $item) {
            $processor->handle($item);
        }

        $this->app->make(FinalizeIngestionBatch::class)->handle($batch->refresh());

        return $batch->refresh();
    }

    /**
     * The stored objects that are assets, as opposed to the sources the test
     * seeded or anything transient.
     *
     * @return list<string>
     */
    private function canonicalKeys(): array
    {
        $paths = Asset::query()->pluck('path')->all();

        return array_values(array_filter(
            $this->store->keys(),
            static fn (string $key): bool => in_array($key, $paths, true),
        ));
    }
}
