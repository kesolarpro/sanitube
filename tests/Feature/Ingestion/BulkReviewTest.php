<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Jobs\ReviewTrackCandidateJob;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\RejectTrackCandidate;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * BULK-002 — deciding about many candidates at once.
 *
 * Nine hundred imported files produce nine hundred candidates, and before this
 * the only way through them was nine hundred page loads.
 *
 * What this adds is a way to *ask*. The decisions are unchanged: each queues a
 * job that calls the same service, runs the same invariants and writes the same
 * audit entry a single decision does. Most of what is asserted here is what
 * that deliberately does **not** do.
 */
final class BulkReviewTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // --------------------------------------------------------------- access

    #[Test]
    public function a_read_only_member_cannot_decide_about_anything(): void
    {
        // The hidden control is presentation; the route middleware is the
        // check — and this one changes the catalogue in bulk.
        Queue::fake();

        $candidate = $this->candidate();

        $this->actingAs($this->user(UserRole::Member))
            ->post('/ingestion/candidates/bulk', ['candidates' => [$candidate->uuid], 'decision' => 'promote'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------- queueing

    #[Test]
    public function a_selection_queues_one_job_per_candidate_and_decides_nothing_in_the_request(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the review queue screen, from the bulk
        // bar that appears once something is selected.
        //
        // A loop of a thousand transactions inside a request is a request that
        // times out halfway, having already changed the catalogue, with no
        // record of where it stopped.
        Queue::fake();

        $candidates = [$this->candidate(), $this->candidate(), $this->candidate()];

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => array_map(static fn (TrackCandidate $c): string => $c->uuid, $candidates),
            'decision' => 'promote',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(ReviewTrackCandidateJob::class, 3);

        $this->assertSame(0, Track::query()->count(), 'Nothing may be decided inside the request.');
    }

    #[Test]
    public function a_repeated_uuid_queues_one_job_rather_than_two(): void
    {
        // The domain would refuse the second as already decided, but a queue
        // full of work that exists to be refused is still a queue full of work.
        Queue::fake();

        $candidate = $this->candidate();

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => [$candidate->uuid, $candidate->uuid, $candidate->uuid],
            'decision' => 'promote',
        ])->assertSessionHasNoErrors();

        Queue::assertPushed(ReviewTrackCandidateJob::class, 1);
    }

    #[Test]
    public function a_uuid_that_names_nothing_is_dropped_rather_than_queued(): void
    {
        // Resolved here so a selection that was half stale does not read as one
        // that was fully accepted.
        Queue::fake();

        $candidate = $this->candidate();

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => [$candidate->uuid, '00000000-0000-7000-8000-000000000000'],
            'decision' => 'promote',
        ])->assertSessionHasNoErrors();

        Queue::assertPushed(ReviewTrackCandidateJob::class, 1);
    }

    #[Test]
    public function a_selection_of_nothing_that_exists_is_refused_by_code(): void
    {
        Queue::fake();

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => ['00000000-0000-7000-8000-000000000000'],
            'decision' => 'promote',
        ])->assertSessionHasErrors(['review' => 'NOTHING_SELECTED']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_selection_larger_than_the_ceiling_is_refused(): void
    {
        // The bound is the difference between "a selection" and "everything,
        // submitted by a script".
        Queue::fake();
        config(['ingestion.max_bulk_review' => 2]);

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => [
                '00000000-0000-7000-8000-000000000001',
                '00000000-0000-7000-8000-000000000002',
                '00000000-0000-7000-8000-000000000003',
            ],
            'decision' => 'promote',
        ])->assertSessionHasErrors('candidates');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_paused_installation_refuses_before_queueing_anything(): void
    {
        // OPS-002. Refusing now, while somebody is looking at the response,
        // beats stranding two hundred jobs in a queue nobody is watching.
        Queue::fake();

        $this->app->make(BackgroundWork::class)->pause(null, 'Maintenance.');

        $candidate = $this->candidate();

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => [$candidate->uuid],
            'decision' => 'promote',
        ])->assertSessionHasErrors(['review' => 'BACKGROUND_WORK_PAUSED']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function rejecting_without_a_reason_is_refused(): void
    {
        // "Rejected, no reason given" is a row nobody can act on months later,
        // which is exactly when it will be read.
        Queue::fake();

        $candidate = $this->candidate();

        $this->actingAs($this->user())->post('/ingestion/candidates/bulk', [
            'candidates' => [$candidate->uuid],
            'decision' => 'reject',
        ])->assertSessionHasErrors('reason');

        Queue::assertNothingPushed();
    }

    // ----------------------------------------------------- what the job does

    #[Test]
    public function the_job_promotes_a_ready_candidate_through_the_ordinary_service(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::Ready);
        $reviewer = $this->user();

        (new ReviewTrackCandidateJob($candidate->uuid, promote: true, reviewerId: $reviewer->getKey()))
            ->handle(...$this->collaborators());

        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->refresh()->status);
        $this->assertSame(1, Track::query()->count());
        $this->assertSame($reviewer->getKey(), $candidate->reviewed_by);
    }

    #[Test]
    public function the_job_never_overrides_what_the_analysis_flagged(): void
    {
        // Overriding is where a person overrules the machine about one
        // recording they have looked at. Doing it a hundred at a time is
        // exactly what the override exists to prevent, so the candidate is
        // refused by name and left in the queue.
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);

        (new ReviewTrackCandidateJob($candidate->uuid, promote: true))->handle(...$this->collaborators());

        $this->assertSame(TrackCandidateStatus::NeedsReview, $candidate->refresh()->status);
        $this->assertSame(0, Track::query()->count());

        $refusal = AuditEvent::query()
            ->where('action', AuditAction::CandidatePromoted->value)
            ->where('subject_uuid', $candidate->uuid)
            ->firstOrFail();

        $this->assertSame('NOT_PROMOTABLE', $refusal->reason);
    }

    #[Test]
    public function a_refusal_does_not_fail_the_job(): void
    {
        // A candidate the domain declines is an ordinary outcome of asking
        // about nine hundred things at once. Throwing would retry three times
        // to be told the same thing, then bury a routine answer in the
        // failed-jobs table.
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);

        (new ReviewTrackCandidateJob($candidate->uuid, promote: true))->handle(...$this->collaborators());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_job_rejects_with_the_reason_it_was_given(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::Ready);

        (new ReviewTrackCandidateJob($candidate->uuid, promote: false, reason: 'Imported twice by mistake.'))
            ->handle(...$this->collaborators());

        $this->assertSame(TrackCandidateStatus::Rejected, $candidate->refresh()->status);
        $this->assertSame('Imported twice by mistake.', $candidate->review_note);
    }

    #[Test]
    public function a_candidate_that_vanished_between_queueing_and_running_is_not_a_failure(): void
    {
        // Trashed, or decided and cleaned up. Nothing to do and nothing wrong.
        (new ReviewTrackCandidateJob('00000000-0000-7000-8000-000000000000', promote: true))
            ->handle(...$this->collaborators());

        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function deciding_the_same_candidate_twice_is_refused_by_the_domain(): void
    {
        // Idempotency the job does not implement itself: it re-reads the row,
        // and `alreadyPromoted` is a refusal the domain already knows how to
        // raise.
        $candidate = $this->candidate(TrackCandidateStatus::Ready);

        (new ReviewTrackCandidateJob($candidate->uuid, promote: true))->handle(...$this->collaborators());
        (new ReviewTrackCandidateJob($candidate->uuid, promote: true))->handle(...$this->collaborators());

        $this->assertSame(1, Track::query()->count(), 'A second run must not make a second track.');
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return array{0: PromoteTrackCandidate, 1: RejectTrackCandidate, 2: RecordAuditEvent}
     */
    private function collaborators(): array
    {
        return [
            $this->app->make(PromoteTrackCandidate::class),
            $this->app->make(RejectTrackCandidate::class),
            $this->app->make(RecordAuditEvent::class),
        ];
    }

    private function candidate(TrackCandidateStatus $status = TrackCandidateStatus::Ready): TrackCandidate
    {
        $assets = $this->app->make(AssetStorageService::class);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        $asset = $assets->store($assets->register(AssetKind::AudioMaster, 'master.wav'), $stream);
        $verified = $this->app->make(AssetVerificationService::class)->verify($asset->refresh());

        $candidate = TrackCandidate::query()->create([
            'source' => IngestionSource::ManualUpload,
            'asset_id' => $verified->id,
            'original_filename' => 'master.wav',
            'suggested_title' => 'Une chanson',
            'status' => TrackCandidateStatus::Pending,
        ]);

        // Written after creation, because the model defaults the status and a
        // create() that quietly ignored the value would leave every row
        // PENDING — which is exactly what these tests exist to tell apart.
        $candidate->forceFill(['status' => $status])->save();

        return $candidate->refresh();
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
