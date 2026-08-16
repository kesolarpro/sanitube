<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ui\Queries\TrackCandidateDetailQuery;
use Tests\TestCase;

/**
 * The three decisions a reviewer can take, from the interface.
 *
 * The claim under test is that the interface is a **surface over CAT-001**,
 * not a second implementation of it. Every guard the domain enforces has to
 * hold when the request arrives through a form: promotion happens once, an
 * unverified master is refused, overruling the machine requires a stated
 * reason, and a MEMBER who may read the queue may decide nothing in it.
 *
 * The route middleware is the authorisation. The `actions` block in the
 * payload is presentation — it decides whether a button is drawn, never
 * whether a request succeeds — and one test below proves the two are
 * independent by posting past a hidden button.
 */
final class CandidateReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ who may act

    #[Test]
    public function every_review_action_is_behind_authentication(): void
    {
        $candidate = $this->candidate();

        $this->post('/ingestion/candidates/'.$candidate->uuid.'/promote')->assertRedirect(route('login'));
        $this->post('/ingestion/candidates/'.$candidate->uuid.'/reject')->assertRedirect(route('login'));
        $this->patch('/ingestion/candidates/'.$candidate->uuid)->assertRedirect(route('login'));
    }

    #[Test]
    public function a_deactivated_account_cannot_decide_anything(): void
    {
        $candidate = $this->candidate();
        $user = $this->user(UserRole::Owner, 'gone@example.test');
        $user->forceFill(['is_active' => false])->save();

        // SEC-001's rule: access is lost at the next request, not when a
        // remember-me cookie expires.
        $this->actingAs($user)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote')
            ->assertRedirect(route('login'));

        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function a_member_may_read_the_queue_and_decide_nothing_in_it(): void
    {
        $candidate = $this->candidate();
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/ingestion/candidates/'.$candidate->uuid)->assertOk();

        $this->actingAs($member)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Anything'])
            ->assertForbidden();

        $this->actingAs($member)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/reject', ['reason' => 'no'])
            ->assertForbidden();

        $this->actingAs($member)
            ->patch('/ingestion/candidates/'.$candidate->uuid, ['suggested_title' => 'no'])
            ->assertForbidden();

        $this->assertSame(0, Track::query()->count());
        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
    }

    #[Test]
    public function a_member_is_not_offered_the_actions_either(): void
    {
        $candidate = $this->candidate();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($candidate, $this->user(UserRole::Member, 'member@example.test'));

        // Presentation, matching the authorisation rather than replacing it.
        $this->assertFalse($payload['actions']['may_review']);
        $this->assertFalse($payload['actions']['can_promote']);
        $this->assertFalse($payload['actions']['can_reject']);
        $this->assertFalse($payload['actions']['can_revise']);
    }

    #[Test]
    public function hiding_a_button_is_never_what_stops_a_request(): void
    {
        // PENDING: the payload offers no promote button at all. The request is
        // refused by the domain regardless, which is the property that makes
        // the flags safe to compute for presentation.
        $candidate = $this->candidate(TrackCandidateStatus::Pending);
        $owner = $this->user();

        $payload = $this->app->make(TrackCandidateDetailQuery::class)->forCandidate($candidate, $owner);
        $this->assertFalse($payload['actions']['can_promote']);
        $this->assertFalse($payload['actions']['can_promote_with_override']);

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Forced'])
            ->assertSessionHasErrors(['review' => 'NOT_PROMOTABLE']);

        $this->assertSame(0, Track::query()->count());
    }

    // -------------------------------------------------------------- promotion

    #[Test]
    public function promoting_a_ready_candidate_creates_exactly_one_draft_track(): void
    {
        $candidate = $this->candidate();
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertRedirect();

        $this->assertSame(1, Track::query()->count());

        $track = Track::query()->firstOrFail();
        $this->assertSame('Nocturne', $track->title);

        // Promotion is not publication, and it mints no identifier.
        $this->assertSame(TrackStatus::Draft, $track->status);
        $this->assertDatabaseCount('external_identifiers', 0);

        $candidate->refresh();
        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->status);
        $this->assertSame($track->getKey(), $candidate->promoted_track_id);

        // Recorded, unlike the API's call. A decision that changed the
        // catalogue and names nobody is a decision nobody can be asked about.
        $this->assertSame($owner->getKey(), $candidate->reviewed_by);
        $this->assertNotNull($candidate->reviewed_at);
    }

    #[Test]
    public function the_reviewer_is_sent_to_the_track_they_made(): void
    {
        $candidate = $this->candidate();

        $this->actingAs($this->user())
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertRedirect('/catalog/tracks/'.Track::query()->firstOrFail()->uuid);
    }

    #[Test]
    public function promoting_twice_creates_one_track_and_refuses_the_second(): void
    {
        $candidate = $this->candidate();
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertRedirect();

        // The retry a double-submitted form produces. It must cost nothing.
        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertSessionHasErrors(['review' => 'ALREADY_PROMOTED']);

        $this->assertSame(1, Track::query()->count());
    }

    #[Test]
    public function a_candidate_whose_master_is_unverified_is_refused(): void
    {
        $candidate = $this->candidate();
        $candidate->asset->forceFill(['status' => AssetStatus::Stored])->save();

        $this->actingAs($this->user())
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertSessionHasErrors(['review' => 'ASSET_NOT_VERIFIED']);

        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function overruling_the_platform_without_a_reason_is_refused(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);
        $owner = $this->user();

        // Override claimed, no note. The domain owns this rule and the form
        // does not restate it, so this is the only place it can be enforced.
        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', [
                'title' => 'Nocturne',
                'override' => true,
            ])
            ->assertSessionHasErrors(['review' => 'OVERRIDE_NEEDS_NOTE']);

        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function a_held_candidate_without_an_override_is_refused(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);

        $this->actingAs($this->user())
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertSessionHasErrors(['review' => 'NOT_PROMOTABLE']);

        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function overruling_the_platform_with_a_reason_records_the_reason(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::NeedsReview);
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', [
                'title' => 'Nocturne',
                'override' => true,
                'note' => 'Listened to it; the ISRC on the manifest belonged to the demo, not this master.',
            ])
            ->assertRedirect();

        $candidate->refresh();

        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->status);
        $this->assertStringContainsString('Listened to it', (string) $candidate->review_note);
        $this->assertSame(1, Track::query()->count());
    }

    #[Test]
    public function a_held_candidate_offers_promotion_only_with_an_override(): void
    {
        $payload = $this->app->make(TrackCandidateDetailQuery::class)
            ->forCandidate($this->candidate(TrackCandidateStatus::NeedsReview), $this->user());

        $this->assertFalse($payload['actions']['can_promote']);
        $this->assertTrue($payload['actions']['can_promote_with_override']);
        $this->assertTrue($payload['actions']['override_requires_note']);
    }

    #[Test]
    public function one_recording_cannot_enter_the_catalogue_twice(): void
    {
        $candidate = $this->candidate();
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertRedirect();

        // A second candidate over the same master — what a re-import produces.
        $second = TrackCandidate::query()->create([
            'source' => IngestionSource::CloudImport,
            'asset_id' => $candidate->asset_id,
            'original_filename' => 'master.wav',
            'suggested_title' => 'Nocturne again',
            'status' => TrackCandidateStatus::Ready,
        ]);

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$second->uuid.'/promote', ['title' => 'Nocturne again'])
            ->assertSessionHasErrors(['review' => 'MASTER_ALREADY_IN_CATALOGUE']);

        $this->assertSame(1, Track::query()->count());
    }

    // --------------------------------------------------------------- rejection

    #[Test]
    public function declining_a_proposal_keeps_it_with_the_reason(): void
    {
        $candidate = $this->candidate();
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/reject', [
                'reason' => 'A rehearsal take that was never meant to be released.',
            ])
            ->assertRedirect();

        $candidate->refresh();

        // Kept, not deleted: re-importing the same folder must not propose it
        // again as though nobody had ever looked.
        $this->assertSame(TrackCandidateStatus::Rejected, $candidate->status);
        $this->assertStringContainsString('rehearsal take', (string) $candidate->review_note);
        $this->assertSame($owner->getKey(), $candidate->reviewed_by);
    }

    #[Test]
    public function declining_without_a_reason_is_refused_by_the_form(): void
    {
        $candidate = $this->candidate();

        $this->actingAs($this->user())
            ->post('/ingestion/candidates/'.$candidate->uuid.'/reject', ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(TrackCandidateStatus::Ready, $candidate->refresh()->status);
    }

    #[Test]
    public function a_promoted_proposal_cannot_be_declined_afterwards(): void
    {
        $candidate = $this->candidate();
        $owner = $this->user();

        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Nocturne'])
            ->assertRedirect();

        // Un-promoting is not a rejection; it is a catalogue deletion, which
        // is a different decision with different consequences.
        $this->actingAs($owner)
            ->post('/ingestion/candidates/'.$candidate->uuid.'/reject', ['reason' => 'changed my mind'])
            ->assertSessionHasErrors(['review' => 'ALREADY_PROMOTED']);

        $this->assertSame(1, Track::query()->count());
    }

    // ---------------------------------------------------------------- revision

    #[Test]
    public function correcting_the_suggested_title_leaves_the_evidence_alone(): void
    {
        $candidate = $this->candidate();
        $candidate->forceFill([
            'metadata' => ['manifest' => ['title' => 'What the spreadsheet said'], 'suggested_title_source' => 'MANIFEST'],
        ])->save();

        $this->actingAs($this->user())
            ->patch('/ingestion/candidates/'.$candidate->uuid, ['suggested_title' => 'Nocturne, Op. 9 No. 2'])
            ->assertRedirect();

        $candidate->refresh();

        $this->assertSame('Nocturne, Op. 9 No. 2', $candidate->suggested_title);

        // The importer's evidence is what the reviewer's own decision rests
        // on. A title correction must not rewrite it.
        $this->assertSame('What the spreadsheet said', $candidate->metadata['manifest']['title'] ?? null);
        $this->assertSame('MANIFEST', $candidate->metadata['suggested_title_source'] ?? null);
    }

    #[Test]
    public function a_decided_proposal_cannot_be_revised(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::Rejected);

        // Editing the suggestion behind a decided candidate would silently
        // rewrite the history of a decision taken on the old value.
        $this->actingAs($this->user())
            ->patch('/ingestion/candidates/'.$candidate->uuid, ['suggested_title' => 'Something else'])
            ->assertSessionHasErrors(['review' => 'ALREADY_DECIDED']);

        $this->assertSame('Master', $candidate->refresh()->suggested_title);
    }

    #[Test]
    public function an_empty_title_is_refused_rather_than_erasing_the_suggestion(): void
    {
        $candidate = $this->candidate();

        $this->actingAs($this->user())
            ->patch('/ingestion/candidates/'.$candidate->uuid, ['suggested_title' => '  '])
            ->assertSessionHasErrors('suggested_title');

        $this->assertSame('Master', $candidate->refresh()->suggested_title);
    }

    // ---------------------------------------------------------------- leakage

    #[Test]
    public function a_refusal_carries_its_code_and_not_the_domains_sentence(): void
    {
        $candidate = $this->candidate(TrackCandidateStatus::Pending);

        $response = $this->actingAs($this->user())
            ->post('/ingestion/candidates/'.$candidate->uuid.'/promote', ['title' => 'Forced']);

        $errors = session('errors');
        $this->assertNotNull($errors);

        // The code travels; the interface translates it. The domain's English
        // is written for a developer reading a log.
        $this->assertSame('NOT_PROMOTABLE', $errors->first('review'));

        $response->assertRedirect();
    }

    // -------------------------------------------------------------- fixtures

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

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Reviewer',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }
}
