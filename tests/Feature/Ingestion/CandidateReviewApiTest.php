<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The review endpoints — the first place in v1 where a request can create a
 * Track.
 *
 * The catalogue was deliberately read-only until now. What makes this
 * acceptable is that the write is a named decision with invariants behind it,
 * not a settable `status` field, and that everything it refuses says why in a
 * form a client can act on.
 */
final class CandidateReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-internal-api-token-for-testing';

    private InMemoryStorageProvider $store;

    private FakeAudioAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');

        config([
            'storage.default' => 'memory',
            'sanitube.api.internal_token' => self::TOKEN,
        ]);

        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->analyzer = new FakeAudioAnalyzer;
        $this->app->instance(AudioAnalyzer::class, $this->analyzer);

        Queue::fake();
    }

    // ---------------------------------------------------------- the guard

    #[Test]
    public function the_review_endpoints_refuse_a_caller_without_the_token(): void
    {
        $candidate = $this->readyCandidate();

        $this->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote')->assertUnauthorized();
        $this->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/reject')->assertUnauthorized();
        $this->patchJson('/api/v1/ingestion/candidates/'.$candidate->uuid)->assertUnauthorized();

        $this->assertSame(0, Track::query()->count());
    }

    // ------------------------------------------------------------ promoting

    #[Test]
    public function promoting_answers_201_with_the_track_that_now_exists(): void
    {
        // 201 rather than the 202 that starts an import: unlike a batch, the
        // thing this request describes exists by the time it answers.
        $candidate = $this->readyCandidate();

        $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Night Bus'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Night Bus')
            ->assertJsonPath('data.status', 'DRAFT');

        $this->assertSame(1, Track::query()->count());
        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->refresh()->status);
    }

    #[Test]
    public function a_refusal_carries_a_code_a_client_can_act_on(): void
    {
        // 422 with no reason is what makes a reviewer guess, and a reviewer
        // who guesses eventually works around the check rather than fixing it.
        $candidate = $this->importOne();

        $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NOT_PROMOTABLE');
    }

    #[Test]
    public function promoting_the_same_candidate_twice_is_refused_by_the_endpoint(): void
    {
        $candidate = $this->readyCandidate();
        $url = '/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote';

        $this->authenticated()->postJson($url)->assertCreated();
        $this->authenticated()->postJson($url)->assertUnprocessable()->assertJsonPath('code', 'ALREADY_PROMOTED');

        $this->assertSame(1, Track::query()->count());
    }

    #[Test]
    public function an_out_of_range_value_is_rejected_by_validation_not_by_the_database(): void
    {
        $candidate = $this->readyCandidate();

        $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote', ['bpm' => 9000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bpm');

        $this->assertSame(0, Track::query()->count());
    }

    // ------------------------------------------------------------ rejecting

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        $candidate = $this->readyCandidate();

        $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/reject', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function rejecting_records_the_decision_and_creates_nothing(): void
    {
        $candidate = $this->readyCandidate();

        $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/reject', [
                'reason' => 'Rehearsal take, not for release.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.review_note', 'Rehearsal take, not for release.');

        $this->assertSame(0, Track::query()->count());
    }

    // ------------------------------------------------------------- revising

    #[Test]
    public function a_patch_can_correct_a_suggestion_but_not_decide_anything(): void
    {
        $candidate = $this->readyCandidate();

        $this->authenticated()
            ->patchJson('/api/v1/ingestion/candidates/'.$candidate->uuid, [
                'suggested_title' => 'Night Bus (Live)',
                // Ignored: a PATCH is not where decisions are taken.
                'status' => 'PROMOTED',
            ])
            ->assertOk()
            ->assertJsonPath('data.suggested_title', 'Night Bus (Live)')
            ->assertJsonPath('data.status', 'READY');

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
    }

    // ------------------------------------------------------------- the read

    #[Test]
    public function a_candidate_carries_the_measurements_a_reviewer_needs(): void
    {
        // A review surface that shows only a filename is one on which every
        // decision is made blind.
        $candidate = $this->readyCandidate();

        $this->authenticated()->getJson('/api/v1/ingestion/candidates/'.$candidate->uuid)
            ->assertOk()
            ->assertJsonPath('data.analysis.duration_ms', 213_546)
            ->assertJsonPath('data.analysis.codec', 'pcm_s24le')
            ->assertJsonPath('data.analysis.channels', 2);
    }

    #[Test]
    public function an_unanalysed_candidate_reports_no_analysis_rather_than_empty_measurements(): void
    {
        // "Not measured" and "measured as nothing" are different facts, and a
        // reviewer must be able to tell them apart.
        $this->analyzer->unavailable();
        $candidate = $this->readyCandidate();

        $this->authenticated()->getJson('/api/v1/ingestion/candidates/'.$candidate->uuid)
            ->assertOk()
            ->assertJsonPath('data.analysis', null);
    }

    #[Test]
    public function the_analysis_never_publishes_the_analysers_raw_output(): void
    {
        // `raw` names codecs, paths and tooling. It is kept for diagnosis and
        // is not part of the published surface.
        $candidate = $this->readyCandidate();

        $response = $this->authenticated()->getJson('/api/v1/ingestion/candidates/'.$candidate->uuid)->assertOk();

        $this->assertArrayNotHasKey('raw', (array) $response->json('data.analysis'));
    }

    #[Test]
    public function a_promoted_track_never_carries_storage_vocabulary(): void
    {
        $candidate = $this->readyCandidate();

        $response = $this->authenticated()
            ->postJson('/api/v1/ingestion/candidates/'.$candidate->uuid.'/promote')
            ->assertCreated();

        $raw = $response->content();

        foreach (['library/01', 'memory', 'masters/', $candidate->asset->path] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, sprintf('[%s] leaked on promotion.', $secret));
        }
    }

    // --------------------------------------------------------------- helpers

    private function authenticated(): self
    {
        return $this->withHeader((string) config('sanitube.api.token_header', 'X-SaniTube-Token'), self::TOKEN);
    }

    private function readyCandidate(string $reference = 'library/01 - Opening.wav'): TrackCandidate
    {
        $candidate = $this->importOne($reference);

        $analyzer = $this->app->make(AnalyzeAsset::class);

        if (AnalyzeAsset::appliesTo($candidate->asset)) {
            $analyzer->handle($candidate->asset);
        }

        $this->app->make(SettleCandidateAfterAnalysis::class)->handle($candidate->refresh());

        return $candidate->refresh();
    }

    private function importOne(string $reference = 'library/01 - Opening.wav'): TrackCandidate
    {
        $this->store->put($reference, 'the recording itself');

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
}
