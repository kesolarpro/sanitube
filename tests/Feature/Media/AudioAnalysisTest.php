<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Jobs\AnalyzeAudioAssetJob;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\ApplyEmbeddedTags;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Technical analysis, and what it is allowed to conclude.
 *
 * The hard requirement behind this ticket is not "measure the audio" — it is
 * that a server which *cannot* measure the audio keeps working. SaniTube has to
 * run on cPanel shared hosting where FFmpeg is frequently absent and the
 * account cannot install it, so most of what follows is about the difference
 * between a missing capability and a bad file.
 */
final class AudioAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private FakeAudioAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // Bound as an instance rather than through the container's own
        // resolution, so a machine that happens to have FFmpeg installed runs
        // exactly the same test as one that does not.
        $this->analyzer = new FakeAudioAnalyzer;
        $this->app->instance(AudioAnalyzer::class, $this->analyzer);

        Queue::fake();
    }

    // ------------------------------------------------------ the happy path

    #[Test]
    public function analysis_records_what_the_analyser_measured(): void
    {
        $candidate = $this->importOne();

        $this->runAnalysis($candidate);

        $analysis = AudioAnalysis::query()->firstOrFail();

        $this->assertTrue($analysis->succeeded);
        $this->assertSame($candidate->asset_id, $analysis->asset_id);
        $this->assertSame('fake', $analysis->analyzer);
        $this->assertSame(213_546, $analysis->duration_ms);
        $this->assertSame('pcm_s24le', $analysis->codec);
        $this->assertSame(48_000, $analysis->sample_rate);
        $this->assertSame(2, $analysis->channels);
        $this->assertNotNull($analysis->analyzed_at);
    }

    #[Test]
    public function a_pending_candidate_becomes_ready_once_it_has_been_analysed(): void
    {
        $candidate = $this->importOne();

        // ING-001 leaves it PENDING on purpose: the bytes are verified, but
        // nothing has looked inside them yet.
        $this->assertSame(TrackCandidateStatus::Pending, $candidate->status);
        $this->assertFalse($candidate->status->isPromotable());

        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
        $this->assertTrue($candidate->status->isPromotable());
    }

    #[Test]
    public function the_object_is_streamed_to_a_real_local_file_for_the_probe(): void
    {
        // The awkward part of the whole ticket. A probe needs a *file*; the
        // platform holds *objects*. An analyser handed a storage key would work
        // on a local disk in development and fail on object storage in
        // production, which is the worst possible place to find out.
        $candidate = $this->importOne(contents: 'the recording itself');

        $this->runAnalysis($candidate);

        $this->assertSame(1, $this->analyzer->calls);
        $this->assertSame('the recording itself', $this->analyzer->lastContents);
    }

    #[Test]
    public function the_temporary_file_does_not_survive_the_analysis(): void
    {
        // 900 masters at 500 MB each would fill the account's disk quota
        // long before the import finished.
        $candidate = $this->importOne();

        $this->runAnalysis($candidate);

        $this->assertNotNull($this->analyzer->lastPath);
        $this->assertFileDoesNotExist($this->analyzer->lastPath);
    }

    // ------------------------------------------ a server that cannot answer

    #[Test]
    public function a_host_without_an_analyser_still_produces_promotable_candidates(): void
    {
        // The cPanel case, and the reason `analysis_required` defaults to
        // false. If an absent binary held every candidate back, nothing would
        // ever reach the catalogue on the platform's primary target.
        $this->analyzer->unavailable();

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
        $this->assertTrue($candidate->status->isPromotable());
    }

    #[Test]
    public function nothing_is_recorded_when_there_was_no_analyser_to_record_it(): void
    {
        // A row saying "failed" would be indistinguishable from a corrupt
        // file, and would still be sitting there long after FFmpeg was
        // installed. Absence is the honest answer.
        $this->analyzer->unavailable();

        $this->runAnalysis($this->importOne());

        $this->assertSame(0, AudioAnalysis::query()->count());
    }

    #[Test]
    public function an_operator_who_requires_analysis_gets_waiting_capability_instead(): void
    {
        // The other half of the choice: a label whose distributor rejects
        // masters below a loudness floor would rather nothing were promotable
        // than have it promoted unmeasured.
        config(['media.analysis_required' => true]);
        $this->analyzer->unavailable();

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $candidate->refresh();

        $this->assertSame(TrackCandidateStatus::WaitingCapability, $candidate->status);
        $this->assertFalse($candidate->status->isPromotable());
        $this->assertFalse($candidate->status->isTerminal(), 'It must be revisitable once FFmpeg appears.');
    }

    #[Test]
    public function installing_ffmpeg_later_settles_the_candidates_that_were_waiting(): void
    {
        // The consequence of WAITING_CAPABILITY being non-terminal, proved
        // rather than asserted in a docblock.
        config(['media.analysis_required' => true]);
        $this->analyzer->unavailable();

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::WaitingCapability, $candidate->refresh()->status);

        $this->analyzer = new FakeAudioAnalyzer;
        $this->app->instance(AudioAnalyzer::class, $this->analyzer);

        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
        $this->assertSame(1, AudioAnalysis::query()->count());
    }

    // -------------------------------------------- a file that cannot answer

    #[Test]
    public function a_file_the_analyser_cannot_read_is_sent_for_review_not_marked_ready(): void
    {
        // Distinct from the case above in the one way that matters: here the
        // analyser ran. Its verdict is about the material, so a person has to
        // look before this becomes a track.
        $this->analyzer->willReturn(AudioAnalysisResult::failed('The file contains no audio stream.'));

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $candidate->refresh();

        $this->assertSame(TrackCandidateStatus::NeedsReview, $candidate->status);
        $this->assertFalse($candidate->status->isPromotable());

        $analysis = AudioAnalysis::query()->firstOrFail();
        $this->assertFalse($analysis->succeeded);
        $this->assertSame('The file contains no audio stream.', $analysis->failure_message);
    }

    /**
     * The analyser's words are stored, and they are not the analyser's to choose.
     *
     * `FfprobeAudioAnalyzer` hands over `$exception->getMessage()` and
     * `RemoteAudioAnalyzer` a worker's refusal reason. This column is durable
     * — read by the internal API, and carried into every database backup taken
     * afterwards — so an address that reaches it is not a transient leak but a
     * leak with copies.
     */
    #[Test]
    public function an_analysis_failure_is_not_stored_with_the_address_it_failed_at(): void
    {
        $this->analyzer->willReturn(AudioAnalysisResult::failed(
            'probe failed: https://bucket.r2.cloudflarestorage.com/masters/x.wav'
                .'?X-Amz-Signature=deadbeefcafe returned 403',
        ));

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $stored = (string) AudioAnalysis::query()->firstOrFail()->failure_message;

        $this->assertStringNotContainsString('X-Amz-Signature', $stored);
        $this->assertStringNotContainsString('bucket.r2.cloudflarestorage.com', $stored);
        $this->assertStringContainsString('403', $stored, 'Scrubbed to nothing says less than an empty cell.');
    }

    #[Test]
    public function a_failed_analysis_is_reported_even_when_analysis_is_not_required(): void
    {
        // `analysis_required = false` makes the measurement optional. It must
        // not make the *finding* optional — that would be a setting that
        // suppresses bad news.
        config(['media.analysis_required' => false]);
        $this->analyzer->willReturn(AudioAnalysisResult::failed('Not an audio file.'));

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::NeedsReview, $candidate->refresh()->status);
        $this->assertFalse(AudioAnalysis::query()->firstOrFail()->succeeded);
    }

    // ------------------------------------------------------------ repetition

    #[Test]
    public function analysing_the_same_asset_twice_does_not_produce_two_rows(): void
    {
        $candidate = $this->importOne();

        $this->runAnalysis($candidate);
        $this->runAnalysis($candidate);

        $this->assertSame(1, AudioAnalysis::query()->count());
        $this->assertSame(1, $this->analyzer->calls, 'The second run must not re-probe the file.');
    }

    #[Test]
    public function a_new_analyser_version_analyses_again_and_keeps_the_old_answer(): void
    {
        // "Why did this track's duration change?" has to have an answer, and
        // it cannot if a newer version overwrote the older one's row.
        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $this->analyzer = (new FakeAudioAnalyzer)
            ->atVersion('2')
            ->willReturn(AudioAnalysisResult::measured(durationMs: 213_600, codec: 'pcm_s24le'));
        $this->app->instance(AudioAnalyzer::class, $this->analyzer);

        $this->runAnalysis($candidate);

        $this->assertSame(2, AudioAnalysis::query()->count());
        $this->assertSame(
            [213_546, 213_600],
            AudioAnalysis::query()->orderBy('id')->pluck('duration_ms')->all(),
        );
    }

    #[Test]
    public function the_database_refuses_a_second_row_for_one_analyser_version(): void
    {
        $asset = $this->importOne()->asset;

        AudioAnalysis::query()->create([
            'asset_id' => $asset->id, 'analyzer' => 'fake', 'analyzer_version' => '1', 'succeeded' => true,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        AudioAnalysis::query()->create([
            'asset_id' => $asset->id, 'analyzer' => 'fake', 'analyzer_version' => '1', 'succeeded' => true,
        ]);
    }

    // ------------------------------------------------- what must not happen

    #[Test]
    public function analysis_never_promotes_a_candidate_or_creates_a_track(): void
    {
        // MED-001 measures. It does not decide what belongs in the catalogue,
        // and READY means "ready for a human to look at", never "published".
        $candidate = $this->importOne();
        $this->runAnalysis($candidate);

        $this->assertSame(0, Track::query()->count());
        $this->assertNull($candidate->refresh()->promoted_track_id);
    }

    #[Test]
    public function analysis_leaves_a_promoted_candidate_exactly_as_it_found_it(): void
    {
        // A late job must not walk a candidate backwards out of a terminal
        // state. Promotion is the transition nothing here may undo.
        $candidate = $this->importOne();
        $candidate->forceFill(['status' => TrackCandidateStatus::Promoted])->save();

        $this->runAnalysis($candidate);

        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->refresh()->status);
    }

    #[Test]
    public function an_unverified_asset_is_not_analysed(): void
    {
        // Analysing bytes nobody has confirmed would describe an object the
        // catalogue does not yet claim is the one it means.
        $candidate = $this->importOne();
        $candidate->asset->forceFill(['status' => AssetStatus::Pending])->save();

        $this->runAnalysis($candidate);

        $this->assertSame(0, AudioAnalysis::query()->count());
        $this->assertSame(0, $this->analyzer->calls);
    }

    #[Test]
    public function a_job_whose_candidate_has_since_vanished_does_nothing(): void
    {
        // The job carries a uuid, not a model, precisely so that it reads the
        // row as it is now — including "not there any more".
        $job = new AnalyzeAudioAssetJob('01920000-0000-7000-8000-000000000000');

        $job->handle(
            $this->app->make(AnalyzeAsset::class),
            $this->app->make(SettleCandidateAfterAnalysis::class),
            $this->app->make(ApplyEmbeddedTags::class),
        );

        $this->assertSame(0, AudioAnalysis::query()->count());
    }

    #[Test]
    public function importing_queues_the_analysis_rather_than_running_it_in_the_request(): void
    {
        $this->importOne();

        Queue::assertPushed(AnalyzeAudioAssetJob::class);
    }

    // ------------------------------------------------------------ the command

    #[Test]
    public function the_command_analyses_the_candidates_that_were_waiting(): void
    {
        // Without this, WAITING_CAPABILITY is a dead end and the only way to
        // analyse 900 already-imported files is to import them again.
        config(['media.analysis_required' => true]);
        $this->analyzer->unavailable();

        $candidate = $this->importOne();
        $this->runAnalysis($candidate);
        $this->assertSame(TrackCandidateStatus::WaitingCapability, $candidate->refresh()->status);

        $this->app->instance(AudioAnalyzer::class, new FakeAudioAnalyzer);

        $this->artisan('sanitube:media:analyze')->assertSuccessful();

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
    }

    #[Test]
    public function the_command_changes_nothing_and_still_succeeds_without_an_analyser(): void
    {
        // A nightly cron that mails an error every night on a supported
        // configuration trains its reader to ignore the mail.
        $this->analyzer->unavailable();
        $candidate = $this->importOne();

        $this->artisan('sanitube:media:analyze')
            ->expectsOutputToContain('No audio analyser is available')
            ->assertSuccessful();

        $this->assertSame(TrackCandidateStatus::Pending, $candidate->refresh()->status);
        $this->assertSame(0, AudioAnalysis::query()->count());
    }

    #[Test]
    public function the_command_says_what_a_bounded_pass_left_behind(): void
    {
        // A truncated pass that reads like a complete one is worse than no
        // pass: the operator stops looking.
        $this->importOne();
        $this->importOne(contents: 'a second recording', reference: 'library/02 - Night Bus.wav');

        $this->artisan('sanitube:media:analyze --limit=1')
            ->expectsOutputToContain('still awaiting analysis')
            ->assertSuccessful();
    }

    // --------------------------------------------------------------- helpers

    /**
     * Import one object through the real ING-001 pipeline and return its
     * candidate. Building the asset by hand would test a shape the platform
     * never actually produces.
     */
    private function importOne(
        string $contents = 'audio bytes',
        string $reference = 'library/01 - Opening.wav',
    ): TrackCandidate {
        $this->store->put($reference, $contents);

        $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            references: [$reference],
        );

        $processor = $this->app->make(ProcessIngestionItem::class);

        foreach (IngestionItem::query()->whereNotNull('active_marker')->get() as $item) {
            $processor->handle($item);
        }

        foreach (IngestionBatch::query()->get() as $batch) {
            $this->app->make(FinalizeIngestionBatch::class)->handle($batch);
        }

        return TrackCandidate::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * Stand in for a worker picking the analysis job up.
     */
    private function runAnalysis(TrackCandidate $candidate): void
    {
        (new AnalyzeAudioAssetJob($candidate->uuid))->handle(
            $this->app->make(AnalyzeAsset::class),
            $this->app->make(SettleCandidateAfterAnalysis::class),
            $this->app->make(ApplyEmbeddedTags::class),
        );
    }
}
