<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidatePromoted;
use SaniTube\Ingestion\Events\TrackCandidateRejected;
use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\RejectTrackCandidate;
use SaniTube\Ingestion\Services\ReviseTrackCandidate;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The door between a proposal and the catalogue.
 *
 * Everything before this point was allowed to guess. Ingestion read a title
 * off a filename; analysis measured what it could; neither may write to the
 * source of truth. Promotion is where a person takes responsibility for that
 * guess, and the tests below are almost entirely about the ways it must
 * refuse.
 */
final class CandidateReviewTest extends TestCase
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

        $this->analyzer = new FakeAudioAnalyzer;
        $this->app->instance(AudioAnalyzer::class, $this->analyzer);

        Queue::fake();
    }

    // ------------------------------------------------------------- promoting

    #[Test]
    public function promoting_a_ready_candidate_creates_one_draft_track(): void
    {
        Event::fake([TrackCandidatePromoted::class]);

        $candidate = $this->readyCandidate();

        $track = $this->promoter()->handle($candidate, ['title' => 'Night Bus']);

        $this->assertSame(1, Track::query()->count());
        $this->assertSame('Night Bus', $track->title);
        $this->assertSame($candidate->asset_id, $track->master_asset_id);

        // DRAFT, always. Promotion says "this belongs in the catalogue", never
        // "this is ready to release" — collapsing the two would let an import
        // reach a distributor.
        $this->assertSame(TrackStatus::Draft, $track->status);
        $this->assertFalse($track->status->isReleasable());

        $candidate->refresh();
        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->status);
        $this->assertSame($track->id, $candidate->promoted_track_id);
        $this->assertNotNull($candidate->reviewed_at);

        Event::assertDispatched(TrackCandidatePromoted::class);
    }

    #[Test]
    public function the_suggested_title_is_used_when_the_reviewer_supplies_none(): void
    {
        $candidate = $this->readyCandidate(reference: 'library/02 - Night Bus.wav');

        $track = $this->promoter()->handle($candidate);

        $this->assertSame('Night Bus', $track->title);
    }

    #[Test]
    public function what_the_reviewer_supplies_overrides_every_suggestion(): void
    {
        // The suggestion was read off a filename and is frequently wrong. The
        // person is the source of truth; that is the whole point of promotion.
        $candidate = $this->readyCandidate(reference: 'library/02 - Night Bus.wav');

        $track = $this->promoter()->handle($candidate, [
            'title' => 'Nightbus (Remastered)',
            'genre_primary' => 'Electronic',
            'recording_year' => 2019,
        ]);

        $this->assertSame('Nightbus (Remastered)', $track->title);
        $this->assertSame('Electronic', $track->genre_primary);
        $this->assertSame(2019, $track->recording_year);
    }

    #[Test]
    public function an_unknown_language_is_recorded_as_undetermined_rather_than_guessed(): void
    {
        // Nothing about an imported file says what language it is in, and a
        // wrong language code reaches DSPs.
        $track = $this->promoter()->handle($this->readyCandidate());

        $this->assertSame(ContentLanguage::UNKNOWN, $track->language_code);
    }

    #[Test]
    public function ticking_instrumental_sets_the_one_language_that_means_it(): void
    {
        // ISO 639-2 already has a code for "no linguistic content", and
        // TrackObserver enforces I8. Deriving it here means a reviewer gets a
        // track rather than a validation error about ISO codes.
        $track = $this->promoter()->handle($this->readyCandidate(), ['is_instrumental' => true]);

        $this->assertTrue($track->is_instrumental);
        $this->assertSame(ContentLanguage::INSTRUMENTAL, $track->language_code);
    }

    #[Test]
    public function choosing_the_no_linguistic_content_code_makes_it_an_instrumental(): void
    {
        // The same invariant approached from the other side, because a
        // reviewer will do it both ways.
        $track = $this->promoter()->handle($this->readyCandidate(), [
            'language_code' => ContentLanguage::INSTRUMENTAL,
        ]);

        $this->assertTrue($track->is_instrumental);
    }

    #[Test]
    public function the_measured_duration_reaches_the_track_without_being_retyped(): void
    {
        $candidate = $this->readyCandidate();

        $track = $this->promoter()->handle($candidate);

        $this->assertSame(213_546, $track->refresh()->duration_ms);
    }

    #[Test]
    public function a_supplied_duration_wins_over_the_measured_one(): void
    {
        // A reviewer may be cataloguing an edit the master does not reflect.
        $track = $this->promoter()->handle($this->readyCandidate(), ['duration_ms' => 180_000]);

        $this->assertSame(180_000, $track->refresh()->duration_ms);
    }

    #[Test]
    public function a_track_with_no_analysis_keeps_a_null_duration_rather_than_a_zero(): void
    {
        // The cPanel case again. A zero would be a number nothing measured.
        $this->analyzer->unavailable();

        $track = $this->promoter()->handle($this->readyCandidate());

        $this->assertNull($track->refresh()->duration_ms);
    }

    #[Test]
    public function declaring_a_recording_already_distributed_marks_it_legacy(): void
    {
        // Load-bearing: LEGACY_IMPORT marks a recording that already carries
        // an ISRC the platform must preserve rather than mint. Only a person
        // knows this, so only a person can say it.
        $track = $this->promoter()->handle($this->readyCandidate(), ['already_distributed' => true]);

        $this->assertSame(TrackSource::LegacyImport, $track->source);
    }

    #[Test]
    public function an_ordinary_import_keeps_its_own_provenance(): void
    {
        $track = $this->promoter()->handle($this->readyCandidate());

        $this->assertSame(TrackSource::CloudImport, $track->source);
    }

    #[Test]
    public function promotion_mints_no_identifier(): void
    {
        // Nothing here assigns an ISRC. A new identifier for a master that may
        // already have been distributed is the one mistake that cannot be
        // taken back once a DSP has seen it.
        $track = $this->promoter()->handle($this->readyCandidate());

        $this->assertSame(0, $track->externalIdentifiers()->count());
    }

    // ------------------------------------------------------ what is refused

    #[Test]
    public function a_candidate_still_awaiting_analysis_cannot_be_promoted(): void
    {
        $candidate = $this->importOne();

        $this->assertSame(TrackCandidateStatus::Pending, $candidate->status);

        $this->expectException(CandidateReviewException::class);

        $this->promoter()->handle($candidate);
    }

    #[Test]
    public function a_candidate_waiting_on_a_capability_cannot_be_promoted_by_override(): void
    {
        // Distinct from NEEDS_REVIEW: there is no verdict here to disagree
        // with. The work simply has not happened, and pretending a reviewer
        // can overrule that would promote an unmeasured master on a host whose
        // operator said measurement was required.
        config(['media.analysis_required' => true]);
        $this->analyzer->unavailable();

        $candidate = $this->importOne();
        $this->settle($candidate);

        $this->assertSame(TrackCandidateStatus::WaitingCapability, $candidate->refresh()->status);

        $this->expectExceptionMessage('cannot be promoted');

        $this->promoter()->handle($candidate, override: true, note: 'I am in a hurry');
    }

    #[Test]
    public function a_candidate_the_analyser_rejected_needs_an_explicit_override(): void
    {
        $candidate = $this->needsReviewCandidate();

        try {
            $this->promoter()->handle($candidate);
            $this->fail('A NEEDS_REVIEW candidate must not promote silently.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('NOT_PROMOTABLE', $exception->reason);
        }

        $track = $this->promoter()->handle(
            $candidate,
            override: true,
            note: 'Exotic codec this build of ffprobe does not know; verified by ear.',
        );

        $this->assertSame(TrackStatus::Draft, $track->status);
        $this->assertStringContainsString('verified by ear', (string) $candidate->refresh()->review_note);
    }

    #[Test]
    public function an_override_without_a_stated_reason_is_refused(): void
    {
        // An override nobody can account for six months later is not an
        // override, it is a gap.
        try {
            $this->promoter()->handle($this->needsReviewCandidate(), override: true);
            $this->fail('An unexplained override must not be accepted.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('OVERRIDE_NEEDS_NOTE', $exception->reason);
        }
    }

    #[Test]
    public function a_candidate_cannot_be_promoted_twice(): void
    {
        $candidate = $this->readyCandidate();

        $this->promoter()->handle($candidate);

        try {
            $this->promoter()->handle($candidate->refresh());
            $this->fail('Promotion happens once.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('ALREADY_PROMOTED', $exception->reason);
        }

        $this->assertSame(1, Track::query()->count());
    }

    #[Test]
    public function a_stale_read_cannot_promote_a_candidate_a_second_time(): void
    {
        // The race the guarded UPDATE exists for: two reviewers both loaded
        // the candidate while it was READY. Reading the status and then
        // writing it would let both through.
        $candidate = $this->readyCandidate();
        $stale = TrackCandidate::query()->findOrFail($candidate->id);

        $this->promoter()->handle($candidate);

        $this->expectException(CandidateReviewException::class);

        // $stale still believes it is READY.
        $this->promoter()->handle($stale);
    }

    #[Test]
    public function the_same_master_cannot_enter_the_catalogue_twice(): void
    {
        // The duplicate-import constraint, at the point where it would do real
        // damage: one recording under two identifiers is what a distributor
        // sees as two releases of the same track.
        $first = $this->readyCandidate();
        $this->promoter()->handle($first);

        // The same bytes under a different name. AST-001 detects it by SHA-256
        // and registers a *second* asset carrying `duplicate_of_asset_id` —
        // the import happened, and it is recorded rather than discarded. So
        // the second candidate's asset id differs, which is exactly why the
        // guard has to follow the duplicate chain instead of comparing ids.
        $second = $this->readyCandidate(reference: 'library/03 - Copy.wav');
        $second->forceFill(['status' => TrackCandidateStatus::Ready])->save();

        $this->assertNotSame($first->asset_id, $second->asset_id);
        $this->assertSame($first->asset_id, $second->asset->duplicate_of_asset_id);

        try {
            $this->promoter()->handle($second);
            $this->fail('The same master must not be catalogued twice.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('MASTER_ALREADY_IN_CATALOGUE', $exception->reason);
        }

        $this->assertSame(1, Track::query()->count());
    }

    #[Test]
    public function overriding_a_duplicate_points_the_track_at_the_original_master(): void
    {
        // A reviewer may legitimately overrule the duplicate finding. When
        // they do, the catalogue must still reference the original master:
        // pointing at the second copy would leave the first unreferenced while
        // the account pays storage for both.
        $first = $this->readyCandidate();
        $original = $first->asset_id;
        $this->promoter()->handle($first);

        $duplicate = $this->readyCandidate(reference: 'library/03 - Copy.wav');

        $this->assertSame(TrackCandidateStatus::Duplicate, $duplicate->status);

        $track = $this->promoter()->handle(
            $duplicate,
            override: true,
            note: 'Same bytes, but this is a separate contractual recording.',
        );

        $this->assertSame($original, $track->master_asset_id);
        $this->assertNotSame($duplicate->asset_id, $track->master_asset_id);
    }

    #[Test]
    public function a_master_that_has_not_been_verified_cannot_be_promoted(): void
    {
        $candidate = $this->readyCandidate();
        $candidate->asset->forceFill(['status' => AssetStatus::Pending])->save();

        try {
            $this->promoter()->handle($candidate);
            $this->fail('An unverified master must not reach the catalogue.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('ASSET_NOT_VERIFIED', $exception->reason);
        }
    }

    #[Test]
    public function a_rejected_candidate_cannot_be_promoted(): void
    {
        $candidate = $this->readyCandidate();
        $this->rejecter()->handle($candidate, 'Duplicate take, keep the other one.');

        $this->expectException(CandidateReviewException::class);

        $this->promoter()->handle($candidate->refresh());
    }

    // ------------------------------------------------------------ rejecting

    #[Test]
    public function rejecting_records_the_reason_and_keeps_the_row(): void
    {
        Event::fake([TrackCandidateRejected::class]);

        $candidate = $this->readyCandidate();

        $this->rejecter()->handle($candidate, 'Rehearsal take, not for release.');

        $candidate->refresh();

        $this->assertSame(TrackCandidateStatus::Rejected, $candidate->status);
        $this->assertSame('Rehearsal take, not for release.', $candidate->review_note);
        $this->assertNotNull($candidate->reviewed_at);

        // Kept, not deleted: re-importing the same folder must not propose it
        // again as though nobody had ever looked.
        $this->assertSame(1, TrackCandidate::query()->count());
        $this->assertSame(0, Track::query()->count());

        Event::assertDispatched(TrackCandidateRejected::class);
    }

    #[Test]
    public function a_rejection_without_a_reason_is_refused(): void
    {
        $this->expectException(CandidateReviewException::class);

        $this->rejecter()->handle($this->readyCandidate(), '   ');
    }

    #[Test]
    public function a_promoted_candidate_cannot_be_rejected_afterwards(): void
    {
        // The track exists. Un-promoting is a catalogue deletion, which is a
        // different decision with different consequences.
        $candidate = $this->readyCandidate();
        $this->promoter()->handle($candidate);

        try {
            $this->rejecter()->handle($candidate->refresh(), 'Changed my mind.');
            $this->fail('A promoted candidate must not be rejectable.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('ALREADY_PROMOTED', $exception->reason);
        }

        $this->assertSame(1, Track::query()->count());
    }

    // ------------------------------------------------------------- revising

    #[Test]
    public function a_reviewer_can_correct_a_suggestion_before_acting_on_it(): void
    {
        $candidate = $this->readyCandidate(reference: 'library/02 - Night Bus.wav');

        $revised = $this->reviser()->handle($candidate, ['suggested_title' => 'Night Bus (Live)']);

        $this->assertSame('Night Bus (Live)', $revised->suggested_title);
        $this->assertSame(TrackCandidateStatus::Ready, $revised->status, 'Revising is not a decision.');
    }

    #[Test]
    public function revising_metadata_merges_rather_than_replacing_it(): void
    {
        // Whatever the importer read out of the file is as much a part of the
        // record as what a reviewer adds, and this is the only copy.
        $candidate = $this->readyCandidate();
        $candidate->forceFill(['metadata' => ['imported_tag' => 'from the file']])->save();

        $revised = $this->reviser()->handle($candidate, ['metadata' => ['note' => 'from the reviewer']]);

        $this->assertSame(
            ['imported_tag' => 'from the file', 'note' => 'from the reviewer'],
            $revised->metadata,
        );
    }

    #[Test]
    public function a_decided_candidate_cannot_be_revised(): void
    {
        // Editing the suggestion behind a promoted candidate would rewrite the
        // history of a decision taken on the old value.
        $candidate = $this->readyCandidate();
        $this->promoter()->handle($candidate);

        try {
            $this->reviser()->handle($candidate->refresh(), ['suggested_title' => 'Something else']);
            $this->fail('A promoted candidate must not be revisable.');
        } catch (CandidateReviewException $exception) {
            $this->assertSame('ALREADY_DECIDED', $exception->reason);
        }
    }

    // --------------------------------------------------------------- helpers

    private function promoter(): PromoteTrackCandidate
    {
        return $this->app->make(PromoteTrackCandidate::class);
    }

    private function rejecter(): RejectTrackCandidate
    {
        return $this->app->make(RejectTrackCandidate::class);
    }

    private function reviser(): ReviseTrackCandidate
    {
        return $this->app->make(ReviseTrackCandidate::class);
    }

    private function readyCandidate(
        string $reference = 'library/01 - Opening.wav',
        string $contents = 'the recording itself',
    ): TrackCandidate {
        $candidate = $this->importOne($reference, $contents);
        $this->settle($candidate);

        return $candidate->refresh();
    }

    private function needsReviewCandidate(): TrackCandidate
    {
        $this->analyzer->willReturn(
            AudioAnalysisResult::failed('The file contains no audio stream.'),
        );

        $candidate = $this->importOne();
        $this->settle($candidate);

        return $candidate->refresh();
    }

    private function importOne(
        string $reference = 'library/01 - Opening.wav',
        string $contents = 'the recording itself',
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
     * Stand in for the analysis worker.
     */
    private function settle(TrackCandidate $candidate): void
    {
        $analyzer = $this->app->make(AnalyzeAsset::class);

        if (AnalyzeAsset::appliesTo($candidate->asset)) {
            $analyzer->handle($candidate->asset);
        }

        $this->app->make(SettleCandidateAfterAnalysis::class)->handle($candidate->refresh());
    }
}
