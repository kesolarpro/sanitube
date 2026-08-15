<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Manifest\Manifest;
use SaniTube\Ingestion\Manifest\ManifestConflictDetector;
use SaniTube\Ingestion\Manifest\ManifestParser;
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
 * Importing a legacy catalogue with the spreadsheet that describes it.
 *
 * The claim under test is that a manifest is **evidence and never authority**.
 * Nothing it says is written into the catalogue by the import. Everything it
 * says travels to the candidate, where a person sees it beside what the file
 * actually contains — and where an ISRC that contradicts the catalogue stops
 * the candidate rather than flowing through it.
 */
final class ManifestImportTest extends TestCase
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

    // ------------------------------------------------- manifest to candidate

    #[Test]
    public function a_manifest_names_what_to_import_and_its_evidence_reaches_the_candidate(): void
    {
        $this->store->put('library/01.wav', 'the recording itself');

        $batch = $this->import(<<<'CSV'
            reference,title,artist,album,disc,track,language,isrc,distributor,notes
            library/01.wav,Nocturne,A Band,First Light,1,3,fr,FRZ031400001,OldCo,from the 2014 masters
            CSV);

        $this->runQueue();

        $item = $batch->items()->firstOrFail();
        $this->assertSame(IngestionItemStatus::Imported, $item->status);

        $candidate = TrackCandidate::query()->findOrFail($item->candidate_id);

        // The manifest title is a better guess than the filename — somebody
        // typed it deliberately — but it is still a guess, so it lands in the
        // field named for guesses.
        $this->assertSame('Nocturne', $candidate->suggested_title);
        $this->assertSame('MANIFEST', $candidate->metadata['suggested_title_source'] ?? null);

        $evidence = $candidate->metadata['manifest'] ?? [];
        $this->assertSame('A Band', $evidence['artist'] ?? null);
        $this->assertSame('First Light', $evidence['release_title'] ?? null);
        $this->assertSame(3, $evidence['track_number'] ?? null);
        $this->assertSame('FRZ031400001', $evidence['isrc'] ?? null);
        $this->assertSame('OldCo', $evidence['legacy_provider'] ?? null);
    }

    #[Test]
    public function nothing_the_manifest_says_reaches_the_catalogue(): void
    {
        $this->store->put('library/01.wav', 'the recording itself');

        $this->import(<<<'CSV'
            reference,title,artist,isrc,upc
            library/01.wav,Nocturne,A Band,FRZ031400001,012345678905
            CSV);

        $this->runQueue();

        // The whole point of a candidate. Importing nine hundred files must
        // not put nine hundred rows in the catalogue, and a manifest naming
        // ISRCs must not assign a single one of them.
        $this->assertSame(0, Track::query()->count());
        $this->assertDatabaseCount('external_identifiers', 0);
    }

    #[Test]
    public function a_file_with_no_manifest_row_still_falls_back_to_its_filename(): void
    {
        $this->store->put('library/01 - Opening.wav', 'bytes');

        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            references: ['library/01 - Opening.wav'],
        );

        $this->runQueue();

        $candidate = TrackCandidate::query()->findOrFail($batch->items()->firstOrFail()->candidate_id);

        $this->assertNotNull($candidate->suggested_title);

        // Null, not an empty array. "Nobody supplied a manifest" and "one was
        // supplied and said nothing" are different facts, and a reviewer
        // reading an empty object would reasonably conclude the second.
        $this->assertNull($candidate->metadata);
    }

    #[Test]
    public function the_manifest_is_recorded_on_the_item_so_a_retry_still_has_it(): void
    {
        $this->store->put('library/01.wav', 'bytes');

        $batch = $this->import("reference,title\nlibrary/01.wav,Nocturne\n");

        // Before any work runs. The worker that imports this file may be a
        // different process on a different day; the operator's CSV is not
        // available to it.
        $item = $batch->items()->firstOrFail();

        $this->assertSame('Nocturne', $item->manifest_metadata['title'] ?? null);
        $this->assertSame('library/01.wav', $item->manifest_metadata['reference'] ?? null);
    }

    // -------------------------------------------------------- ISRC conflict

    #[Test]
    public function an_isrc_the_catalogue_already_holds_stops_the_candidate(): void
    {
        $held = Track::factory()->create();
        $this->assign($held, 'FRZ031400001');

        $this->store->put('library/01.wav', 'bytes');

        $batch = $this->import("reference,title,isrc\nlibrary/01.wav,Nocturne,FR-Z03-14-00001\n");
        $this->runQueue();

        $candidate = TrackCandidate::query()->findOrFail($batch->items()->firstOrFail()->candidate_id);

        $this->assertSame(TrackCandidateStatus::NeedsReview, $candidate->status);

        $conflict = $candidate->metadata['manifest_conflicts'][0] ?? [];
        $this->assertSame(ManifestConflictDetector::ISRC_ALREADY_ASSIGNED, $conflict['code'] ?? null);
        $this->assertSame('FRZ031400001', $conflict['isrc'] ?? null);
        $this->assertSame($held->uuid, $conflict['held_by_track'] ?? null);
    }

    #[Test]
    public function a_conflicting_import_still_leaves_the_original_identifier_alone(): void
    {
        $held = Track::factory()->create();
        $this->assign($held, 'FRZ031400001');

        $this->store->put('library/01.wav', 'bytes');
        $this->import("reference,title,isrc\nlibrary/01.wav,Nocturne,FRZ031400001\n");
        $this->runQueue();

        // One active ISRC, still on the track that had it. The detector
        // reports; it never resolves.
        $this->assertDatabaseCount('external_identifiers', 1);
        $this->assertSame(1, $held->externalIdentifiers()->count());
    }

    #[Test]
    public function a_revoked_isrc_is_not_a_conflict(): void
    {
        $held = Track::factory()->create();
        $identifier = $this->assign($held, 'FRZ031400001');
        $identifier->forceFill(['active_marker' => null])->save();

        $this->store->put('library/01.wav', 'bytes');

        $batch = $this->import("reference,title,isrc\nlibrary/01.wav,Nocturne,FRZ031400001\n");
        $this->runQueue();

        $candidate = TrackCandidate::query()->findOrFail($batch->items()->firstOrFail()->candidate_id);

        // Keeping revoked rows exists precisely so a value can legitimately be
        // in use again. Treating history as a conflict would make every
        // deliberate reassignment look like a mistake.
        $this->assertArrayNotHasKey('manifest_conflicts', $candidate->metadata ?? []);
        $this->assertNotSame(TrackCandidateStatus::NeedsReview, $candidate->status);
    }

    #[Test]
    public function a_upc_the_catalogue_already_holds_is_an_observation_and_not_a_conflict(): void
    {
        $this->store->put('library/01.wav', 'first');
        $this->store->put('library/02.wav', 'second');

        // Twelve rows of one album legitimately repeat one UPC, very often for
        // a release the catalogue already has. Treating that as a conflict
        // would send an entire album to review for being an album.
        $batch = $this->import(<<<'CSV'
            reference,title,upc
            library/01.wav,One,012345678905
            library/02.wav,Two,012345678905
            CSV);

        $this->runQueue();

        foreach ($batch->items as $item) {
            $candidate = TrackCandidate::query()->findOrFail($item->candidate_id);
            $this->assertNotSame(TrackCandidateStatus::NeedsReview, $candidate->status);
        }
    }

    // ------------------------------------------------------------ selection

    #[Test]
    public function a_manifest_may_not_be_combined_with_a_prefix_or_references(): void
    {
        $manifest = $this->parse("reference,title\nlibrary/01.wav,One\n");

        $this->expectException(IngestionException::class);
        $this->expectExceptionMessageMatches('/two things naming the batch/');

        $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            prefix: 'library',
            manifest: $manifest,
        );
    }

    // ---------------------------------------------------------- idempotence

    #[Test]
    public function re_running_the_same_manifest_adds_nothing_to_the_catalogue(): void
    {
        $this->store->put('library/01.wav', 'bytes');

        $csv = "reference,title\nlibrary/01.wav,Nocturne\n";

        $first = $this->import($csv);
        $this->runQueue();

        // A second run of the same spreadsheet is the normal way an operator
        // recovers from a partial import, so what it must not do is the point.
        // ING-001 re-reads the source rather than trusting that a reference
        // imported yesterday still holds the same bytes today — an object can
        // be replaced under its key — so a second asset row *is* written, and
        // the checksum then resolves it to the first one.
        $second = $this->import($csv);
        $this->runQueue();

        $secondItem = $second->items()->firstOrFail()->refresh();
        $this->assertSame(IngestionItemStatus::Duplicate, $secondItem->status);

        $firstAsset = $first->items()->firstOrFail()->asset_id;
        $secondCandidate = TrackCandidate::query()->findOrFail($secondItem->candidate_id);

        $this->assertSame(TrackCandidateStatus::Duplicate, $secondCandidate->status);
        $this->assertSame($firstAsset, $secondCandidate->matched_asset_id);

        // What must never grow: the catalogue and the identifiers. Re-running
        // an import is safe because of this, not because nothing was written.
        $this->assertSame(0, Track::query()->count());
        $this->assertDatabaseCount('external_identifiers', 0);
    }

    #[Test]
    public function a_retry_of_an_item_still_in_flight_is_recognised_as_the_same_intent(): void
    {
        $this->store->put('library/01.wav', 'bytes');

        $csv = "reference,title\nlibrary/01.wav,Nocturne\n";

        $this->import($csv);

        // Nothing has run yet: the first item is still PENDING. A second
        // submission of the same reference is the same intent, and is recorded
        // as skipped rather than becoming a second upload.
        $second = $this->import($csv);
        $item = $second->items()->firstOrFail();

        $this->assertSame(IngestionItemStatus::Skipped, $item->status);

        $this->runQueue();

        $this->assertDatabaseCount('assets', 1);
        $this->assertSame(1, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_manifest_of_nine_hundred_rows_imports_and_the_failures_do_not_take_the_rest(): void
    {
        config(['ingestion.max_batch' => 1000]);

        $csv = "reference,title,isrc\n";

        for ($i = 1; $i <= 900; $i++) {
            $reference = sprintf('library/%03d.wav', $i);

            // Three deliberately broken rows, in the middle rather than at the
            // end: the interesting failure is the one that could abandon the
            // rows after it.
            $isrc = in_array($i, [300, 301, 302], true) ? 'NOT-AN-ISRC' : '';

            $this->store->put($reference, 'bytes '.$i);
            $csv .= sprintf("%s,Track %d,%s\n", $reference, $i, $isrc);
        }

        $manifest = $this->parse($csv);

        $this->assertSame(897, $manifest->acceptedCount());
        $this->assertSame(3, $manifest->refusedCount());

        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            manifest: $manifest,
        );

        $this->assertSame(897, $batch->total());
    }

    // ------------------------------------------------------------- helpers

    private function import(string $csv): IngestionBatch
    {
        return $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            manifest: $this->parse($csv),
        );
    }

    private function parse(string $csv): Manifest
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);

        try {
            return $this->app->make(ManifestParser::class)->parse($stream);
        } finally {
            fclose($stream);
        }
    }

    private function assign(Track $track, string $isrc): ExternalIdentifier
    {
        return $this->app->make(AssignExternalIdentifier::class)(
            entity: $track,
            type: ExternalIdentifierType::Isrc,
            value: $isrc,
            source: ExternalIdentifierSource::Import,
        );
    }

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
