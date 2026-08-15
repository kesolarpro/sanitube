<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Manifest\ManifestConflictDetector;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\IngestionBatchIndexQuery;
use SaniTube\Ui\Queries\TrackCandidateDetailQuery;
use SaniTube\Ui\Queries\TrackCandidateIndexQuery;
use Tests\TestCase;

/**
 * The screens that report what an import did.
 *
 * Three claims are what these tests exist to hold:
 *
 *   - **Nothing about storage leaves the server.** No object key, no disk, no
 *     bucket, no failure message quoting a driver. A screen that shows an
 *     operator where their bytes live is a screen that shows everybody.
 *   - **An empty bucket is a real zero.** "No failures" and "the failed count
 *     was not returned" must not look the same to somebody watching nine
 *     hundred files import.
 *   - **A page costs a bounded number of queries.** Counts per batch are
 *     tempting to compute per row, and fifty batches would then be fifty
 *     queries — invisible in development, first thing to hurt in production.
 */
final class IngestionScreensTest extends TestCase
{
    use RefreshDatabase;

    private ?User $viewer = null;

    // --------------------------------------------------------------- access

    #[Test]
    public function every_ingestion_screen_is_behind_authentication(): void
    {
        $batch = $this->batch();
        $candidate = $this->candidate();

        $this->get('/ingestion/batches')->assertRedirect(route('login'));
        $this->get('/ingestion/batches/'.$batch->uuid)->assertRedirect(route('login'));
        $this->get('/ingestion/candidates')->assertRedirect(route('login'));
        $this->get('/ingestion/candidates/'.$candidate->uuid)->assertRedirect(route('login'));
    }

    #[Test]
    public function screens_are_addressed_by_uuid_and_never_by_internal_id(): void
    {
        $batch = $this->batch();
        $candidate = $this->candidate();
        $user = $this->user();

        $this->actingAs($user)->get('/ingestion/batches/'.$batch->uuid)->assertOk();
        $this->actingAs($user)->get('/ingestion/candidates/'.$candidate->uuid)->assertOk();

        $this->actingAs($user)->get('/ingestion/batches/'.$batch->getKey())->assertNotFound();
        $this->actingAs($user)->get('/ingestion/candidates/'.$candidate->getKey())->assertNotFound();
    }

    // ------------------------------------------------------------ the lists

    #[Test]
    public function the_batch_list_counts_every_item_state_including_the_empty_ones(): void
    {
        $batch = $this->batch();
        $this->item($batch, IngestionItemStatus::Imported);
        $this->item($batch, IngestionItemStatus::Imported);
        $this->item($batch, IngestionItemStatus::Failed);

        $page = $this->app->make(IngestionBatchIndexQuery::class)->paginate();
        $counts = $page['rows'][0]['items'];

        $this->assertSame(3, $counts['total']);
        $this->assertSame(2, $counts[IngestionItemStatus::Imported->value]);
        $this->assertSame(1, $counts[IngestionItemStatus::Failed->value]);

        // The point. A state with no rows is a real 0, not an absent key —
        // otherwise a template renders nothing and the reader concludes the
        // count is unavailable rather than zero.
        $this->assertSame(0, $counts[IngestionItemStatus::Duplicate->value]);
        $this->assertSame(0, $counts[IngestionItemStatus::Skipped->value]);
        $this->assertSame(0, $counts[IngestionItemStatus::NeedsReview->value]);
    }

    #[Test]
    public function one_batch_never_counts_another_batchs_items(): void
    {
        $mine = $this->batch();
        $theirs = $this->batch();

        $this->item($mine, IngestionItemStatus::Imported);
        $this->item($theirs, IngestionItemStatus::Failed);
        $this->item($theirs, IngestionItemStatus::Failed);

        $rows = $this->app->make(IngestionBatchIndexQuery::class)->paginate()['rows'];
        $byUuid = array_column($rows, null, 'uuid');

        $this->assertSame(1, $byUuid[$mine->uuid]['items']['total']);
        $this->assertSame(0, $byUuid[$mine->uuid]['items'][IngestionItemStatus::Failed->value]);
        $this->assertSame(2, $byUuid[$theirs->uuid]['items'][IngestionItemStatus::Failed->value]);
    }

    #[Test]
    public function counting_a_page_of_batches_costs_one_query_not_one_per_batch(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $batch = $this->batch();
            $this->item($batch, IngestionItemStatus::Imported);
            $this->item($batch, IngestionItemStatus::Failed);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $rows = $this->app->make(IngestionBatchIndexQuery::class)->paginate()['rows'];

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(12, $rows);

        // One for the page of batches, one for every count on it. Anything
        // that grows with the number of rows is the bug this asserts against.
        $this->assertLessThanOrEqual(2, count($queries), 'The batch list should not query per row.');
    }

    #[Test]
    public function a_refused_filter_says_so_rather_than_showing_an_unfiltered_list(): void
    {
        $this->batch();

        $this->actingAs($this->user())
            ->getJson('/ingestion/batches?status=NOT_A_STATUS')
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->getJson('/ingestion/candidates?status=NOT_A_STATUS')
            ->assertStatus(422);
    }

    #[Test]
    public function a_cursor_from_another_list_is_refused_rather_than_crashing(): void
    {
        // A cursor that decodes cleanly but describes a different ordering
        // reaches the paginator and surfaces as a 500. It is a mangled URL.
        $foreign = base64_encode(json_encode(['title' => 'x', 'uuid' => 'y', '_pointsToNextItems' => true]) ?: '');

        $this->actingAs($this->user())
            ->getJson('/ingestion/batches?cursor='.urlencode($foreign))
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->getJson('/ingestion/candidates?cursor='.urlencode($foreign))
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->getJson('/ingestion/batches/'.$this->batch()->uuid.'?cursor='.urlencode($foreign))
            ->assertStatus(422);
    }

    #[Test]
    public function the_review_queue_can_be_narrowed_to_what_is_waiting_on_a_person(): void
    {
        $this->candidate(TrackCandidateStatus::Ready);
        $this->candidate(TrackCandidateStatus::NeedsReview);
        $this->candidate(TrackCandidateStatus::Pending);
        $this->candidate(TrackCandidateStatus::Promoted);

        $rows = $this->app->make(TrackCandidateIndexQuery::class)
            ->paginate(['awaiting_review' => true])['rows'];

        $statuses = array_column($rows, 'status');
        sort($statuses);

        // READY is included: a candidate the platform has finished with is
        // still waiting for somebody to decide about it.
        $this->assertSame(['NEEDS_REVIEW', 'READY'], $statuses);
    }

    // ------------------------------------------------------------- leakage

    #[Test]
    public function no_object_key_disk_or_bucket_reaches_any_ingestion_screen(): void
    {
        $batch = $this->batch();
        $item = $this->item($batch, IngestionItemStatus::Failed);

        $item->forceFill([
            'source_reference' => 'private-bucket/secret-prefix/track.wav',
            'failure_message' => 'S3 said 403 for s3://private-bucket/secret-prefix/track.wav',
        ])->save();

        $body = $this->actingAs($this->user())
            ->get('/ingestion/batches/'.$batch->uuid)
            ->assertOk()
            ->content();

        // The reference, the bucket and the driver's own sentence are all
        // absent. The filename — which the operator already knows and which a
        // failure report is useless without — is present.
        $this->assertStringNotContainsString('private-bucket', $body);
        $this->assertStringNotContainsString('secret-prefix', $body);
        $this->assertStringNotContainsString('S3 said 403', $body);
        $this->assertStringNotContainsString('source_reference', $body);
        $this->assertStringContainsString($item->original_filename, $body);
    }

    #[Test]
    public function the_candidate_screen_never_carries_a_playable_url(): void
    {
        $candidate = $this->candidate();

        $body = $this->actingAs($this->user())
            ->get('/ingestion/candidates/'.$candidate->uuid)
            ->assertOk()
            ->content();

        // The same rule the asset screen follows. A signed URL is a bearer
        // credential and is never minted by a page load.
        $this->assertStringNotContainsString('X-Amz-Signature', $body);
        $this->assertStringNotContainsString('signature=', $body);
        $this->assertStringNotContainsString($candidate->asset->path, $body);
    }

    #[Test]
    public function an_analyser_failure_message_never_reaches_the_reviewer(): void
    {
        $candidate = $this->candidate();

        DB::table('audio_analyses')->insert([
            'uuid' => (string) Str::uuid7(),
            'asset_id' => $candidate->asset_id,
            'analyzer' => 'ffprobe',
            'analyzer_version' => '7.0',
            'succeeded' => false,
            'failure_message' => 'ffprobe: /var/www/storage/app/masters/deadbeef.wav: Invalid data',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $body = $this->actingAs($this->user())
            ->get('/ingestion/candidates/'.$candidate->uuid)
            ->assertOk()
            ->content();

        // The message quotes the analyser, and on a failed read that quote is
        // a filesystem path. `succeeded: false` is what a reviewer acts on.
        $this->assertStringNotContainsString('/var/www/storage', $body);
        $this->assertStringNotContainsString('Invalid data', $body);
    }

    // ------------------------------------------------------ candidate detail

    #[Test]
    public function the_manifest_is_presented_as_a_claim_and_never_as_catalogue_data(): void
    {
        $candidate = $this->candidate();

        $candidate->forceFill([
            'suggested_title' => 'Nocturne',
            'metadata' => [
                'manifest' => [
                    'line' => 4,
                    'reference' => 'library/01.wav',
                    'title' => 'Nocturne',
                    'artist' => 'A Band',
                    'isrc' => 'FRZ031400001',
                ],
                'suggested_title_source' => 'MANIFEST',
            ],
        ])->save();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($candidate->refresh(), $this->user());

        // The key is `claimed`. It is the last chance to say, before a template
        // renders it, that nobody has established any of this.
        $this->assertSame('Nocturne', $payload['manifest']['claimed']['title']);
        $this->assertSame('A Band', $payload['manifest']['claimed']['artist']);
        $this->assertSame(4, $payload['manifest']['line']);
        $this->assertSame('MANIFEST', $payload['suggested_title_source']);

        // And the reference the manifest named is not part of it.
        $this->assertArrayNotHasKey('reference', $payload['manifest']['claimed']);
        $this->assertStringNotContainsString('library/01.wav', json_encode($payload) ?: '');
    }

    #[Test]
    public function no_manifest_reads_as_no_manifest_rather_than_an_empty_one(): void
    {
        $candidate = $this->candidate();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($candidate, $this->user());

        // Null, not []. "Nobody supplied one" and "one was supplied and said
        // nothing" are different facts about the import.
        $this->assertNull($payload['manifest']);
    }

    #[Test]
    public function a_conflict_is_carried_to_the_screen_with_the_track_that_holds_it(): void
    {
        $held = Track::factory()->create();
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);

        $candidate->forceFill([
            'metadata' => [
                'manifest' => ['isrc' => 'FRZ031400001'],
                'manifest_conflicts' => [[
                    'code' => ManifestConflictDetector::ISRC_ALREADY_ASSIGNED,
                    'isrc' => 'FRZ031400001',
                    'held_by_track' => $held->uuid,
                ]],
            ],
        ])->save();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($candidate->refresh(), $this->user());

        $this->assertCount(1, $payload['manifest']['conflicts']);
        $this->assertSame($held->uuid, $payload['manifest']['conflicts'][0]['held_by_track']);
    }

    #[Test]
    public function an_unanalysed_candidate_reports_nothing_measured_rather_than_zeroes(): void
    {
        $candidate = $this->candidate();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($candidate, $this->user());

        // A host without FFmpeg produces this permanently and legitimately. An
        // object of nulls would read as "measured, and everything was zero".
        $this->assertNull($payload['analysis']);
    }

    #[Test]
    public function the_batch_detail_links_each_item_to_the_proposal_it_produced(): void
    {
        $batch = $this->batch();
        $candidate = $this->candidate();
        $item = $this->item($batch, IngestionItemStatus::Imported);
        $item->forceFill(['candidate_id' => $candidate->getKey()])->save();

        $body = $this->actingAs($this->user())
            ->get('/ingestion/batches/'.$batch->uuid)
            ->assertOk()
            ->content();

        $this->assertStringContainsString($candidate->uuid, $body);
    }

    // ------------------------------------------------------------ navigation

    #[Test]
    public function the_ingestion_screens_are_reachable_from_the_navigation(): void
    {
        $navigation = $this->app->make(NavigationTree::class)->for($this->user());

        $library = null;

        foreach ($navigation as $section) {
            if ($section['key'] === 'library') {
                $library = $section;
            }
        }

        $this->assertIsArray($library);

        $hrefs = array_column($library['children'], 'href', 'key');

        $this->assertSame('/ingestion/batches', $hrefs['ingestion']);
        $this->assertSame('/ingestion/candidates', $hrefs['candidates']);

        // `available` is presentation, never authorisation — but a section
        // whose screen exists and reads as unavailable is a section nobody
        // finds.
        $available = array_column($library['children'], 'available', 'key');

        $this->assertTrue($available['ingestion']);
        $this->assertTrue($available['candidates']);
    }

    // --------------------------------------------------------------- fixtures

    private function batch(IngestionBatchStatus $status = IngestionBatchStatus::Completed): IngestionBatch
    {
        return IngestionBatch::query()->create([
            'source' => IngestionSource::CloudImport,
            'status' => $status,
        ]);
    }

    private function item(IngestionBatch $batch, IngestionItemStatus $status): IngestionItem
    {
        static $n = 0;
        $n++;

        return $batch->items()->create([
            'ingestion_key' => hash('sha256', 'item-'.$n),
            'source_reference' => 'library/'.$n.'.wav',
            'original_filename' => sprintf('%02d - Track.wav', $n),
            'status' => $status,
            'failure_code' => $status === IngestionItemStatus::Failed
                ? IngestionFailureCode::ProviderUnavailable
                : null,
        ]);
    }

    private function candidate(TrackCandidateStatus $status = TrackCandidateStatus::Ready): TrackCandidate
    {
        $asset = Asset::factory()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
        ]);

        return TrackCandidate::query()->create([
            'source' => IngestionSource::CloudImport,
            'asset_id' => $asset->getKey(),
            'original_filename' => 'master.wav',
            'suggested_title' => 'Master',
            'status' => $status,
        ]);
    }

    /**
     * The signed-in operator, created once per test.
     *
     * Memoised rather than created per call: several tests need the viewer in
     * two places, and a second `create()` collides on the unique email — a
     * failure about the fixture rather than about the screen.
     */
    private function user(UserRole $role = UserRole::Owner): User
    {
        if ($this->viewer instanceof User) {
            return $this->viewer;
        }

        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $this->viewer = $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
