<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\AI\AiManager;
use SaniTube\AI\Testing\FakeAiProvider;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Exceptions\TrackMetadataException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\SetTrackMetadata;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Exceptions\SuggestionDecisionException;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Enrichment\Services\DecideSuggestion;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Transcription\Models\Transcript;
use Tests\TestCase;

/**
 * ENR-004 — a person answering what a model proposed.
 *
 * **This is where ADR-0016's boundary is actually enforced**, against a click
 * rather than in a docblock. Everything before this stored opinions; this is
 * the only path by which one becomes catalogue data.
 *
 * The claim these tests defend is that acceptance is not a copy. It goes
 * through the catalogue's own editor, meets the same validation and the same
 * refusals a person editing a track directly would meet, and writes what the
 * reviewer confirmed rather than what the model said.
 */
final class SuggestionDecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A configured model, because enrichment eligibility asks for one first.
     *
     * The regeneration tests would otherwise be refused
     * `ENRICHMENT_MODEL_UNAVAILABLE` before reaching anything this file is
     * about -- which is correct behaviour, and the wrong thing to be testing
     * here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = new AiManager([], 'fake');
        $manager->register('fake', new FakeAiProvider);

        $this->app->forgetInstance(AiManager::class);
        $this->app->instance(AiManager::class, $manager);
    }

    // ------------------------------------------------------------- accepting

    #[Test]
    public function accepting_writes_catalogue_data_through_the_catalogue(): void
    {
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        $updated = $this->decisions()->accept($suggestion, $this->user());

        $this->assertSame('Une chanson', $updated->title);
        $this->assertSame('fr', $updated->language_code);
        $this->assertSame('Folk', $updated->genre_primary);
        $this->assertSame('Ambient', $updated->genre_secondary);
        $this->assertSame(SuggestionStatus::Accepted, $suggestion->fresh()?->status);
    }

    #[Test]
    public function the_catalogue_refuses_what_it_would_refuse_from_a_person(): void
    {
        // The whole point of routing through the catalogue's editor. A
        // released track's metadata is what a distributor was given, and an
        // accepted suggestion must not be a way around that.
        $track = $this->track();
        $track->forceFill(['status' => TrackStatus::Released])->save();
        $suggestion = $this->suggestion($track->master_asset_id);

        try {
            $this->decisions()->accept($suggestion, $this->user());
            $this->fail('A released track must not be rewritten by an accepted suggestion.');
        } catch (TrackMetadataException $refused) {
            $this->assertSame('TRACK_RELEASED', $refused->reason);
        }

        // And the suggestion is left undecided rather than marked accepted
        // with nothing written -- the refusal happens inside the transaction.
        $this->assertSame(SuggestionStatus::Proposed, $suggestion->fresh()?->status);
        $this->assertSame('An older title', $track->fresh()?->title);
    }

    #[Test]
    public function a_reviewer_may_correct_the_model_before_accepting(): void
    {
        // Edit-before-accept is the ordinary case. A model's title is right
        // about half the time and nearly right rather often, and a queue
        // offering only accept-or-reject trains people to accept nearly-right.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        $updated = $this->decisions()->accept($suggestion, $this->user(), [
            'title' => 'Une Chanson Ancienne',
            'genres' => ['Chanson'],
        ]);

        $this->assertSame('Une Chanson Ancienne', $updated->title);
        $this->assertSame('Chanson', $updated->genre_primary);
        $this->assertNull($updated->genre_secondary);

        // The reviewer said nothing about language, so the model's answer
        // stands.
        $this->assertSame('fr', $updated->language_code);
    }

    #[Test]
    public function the_stored_suggestion_still_says_what_the_model_said(): void
    {
        // "What did the model propose" and "what did the person accept" are
        // different questions, and a row that answered only the second would
        // make a bad prompt version invisible.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        $this->decisions()->accept($suggestion, $this->user(), ['title' => 'Something else entirely']);

        $this->assertSame('Une chanson', $suggestion->fresh()?->suggested_title);
    }

    #[Test]
    public function an_acceptance_records_who_and_whether_they_changed_it(): void
    {
        // A prompt version whose answers are always edited before acceptance
        // is a prompt version that is not working, and that is invisible from
        // acceptance counts alone.
        $track = $this->track();
        $reviewer = $this->user();

        $untouched = $this->suggestion($track->master_asset_id);
        $this->decisions()->accept($untouched, $reviewer);

        $this->assertSame($reviewer->id, $untouched->fresh()->decided_by);
        $this->assertNotNull($untouched->fresh()->decided_at);
        $this->assertFalse($this->context(AuditAction::SuggestionAccepted)['edited']);

        $second = $this->track('another-master.wav');
        $edited = $this->suggestion($second->master_asset_id);
        $this->decisions()->accept($edited, $reviewer, ['title' => 'A better title']);

        $this->assertTrue($this->context(AuditAction::SuggestionAccepted)['edited']);
    }

    #[Test]
    public function the_audit_line_names_the_fields_and_not_the_values(): void
    {
        // An audit log is a record of what was touched. Filling it with
        // catalogue text would make it a second copy of the catalogue.
        $track = $this->track();
        $this->decisions()->accept($this->suggestion($track->master_asset_id), $this->user());

        $context = $this->context(AuditAction::SuggestionAccepted);

        // A joined string rather than a list, and that is not cosmetic: the
        // audit scrubber keeps only string keys matching a strict pattern, so
        // a list arrives as an empty array. Written as a list first, this
        // assertion is what caught it.
        $this->assertSame('title,language_code,genre_primary,genre_secondary', $context['fields'] ?? null);
        $this->assertStringNotContainsString('Une chanson', (string) json_encode($context));
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function a_suggestion_cannot_be_decided_twice(): void
    {
        // A resubmitted form would otherwise rewrite who decided and when --
        // and, worse, apply the suggestion a second time over whatever a
        // person had edited since.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);
        $decisions = $this->decisions();

        $decisions->accept($suggestion, $this->user());

        $this->expectException(SuggestionDecisionException::class);

        $decisions->accept($suggestion->fresh() ?? $suggestion, $this->user());
    }

    #[Test]
    public function an_already_decided_suggestion_says_so_rather_than_reporting_the_next_problem(): void
    {
        // Order of refusals, and it is not cosmetic. Checked last, a decided
        // suggestion on an asset with no track would come back
        // `SUGGESTION_NO_TRACK` -- a true statement about the asset and an
        // entirely wrong instruction to the reviewer, who would go looking for
        // a track to create instead of noticing that a colleague had already
        // answered.
        $suggestion = $this->suggestion($this->asset()->id);
        $reviewer = $this->user();

        $this->decisions()->reject($suggestion, $reviewer);

        try {
            $this->decisions()->accept($suggestion->fresh() ?? $suggestion, $reviewer);
            $this->fail('A decided suggestion must not be accepted.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_ALREADY_DECIDED', $refused->reason);

            // And it names the status it actually has, not a hard-coded one.
            // "Already ACCEPTED" about a rejected row is misinformation a
            // reviewer acts on.
            $this->assertStringContainsString(SuggestionStatus::Rejected->value, $refused->getMessage());
        }
    }

    #[Test]
    public function two_reviewers_answering_at_once_cannot_both_win(): void
    {
        // **The guard that actually matters, and the one a fresh-model test
        // cannot reach.** Both requests read a PROPOSED row, so both get past
        // the status check; only the guarded UPDATE can separate them, and it
        // is atomic on every engine in the matrix while read-then-write is not.
        //
        // Simulated by holding a stale in-memory row, which is exactly what
        // the loser of a race has: its own copy says PROPOSED and the database
        // says otherwise. Without this the second acceptance would land on top
        // of whatever the first reviewer wrote.
        $track = $this->track();
        $stale = $this->suggestion($track->master_asset_id);
        $decisions = $this->decisions();

        $decisions->accept($this->suggestion($track->master_asset_id)->fresh() ?? $stale, $this->user());

        // A second suggestion for the same track was decided above; now decide
        // the first one's row out from under the stale copy.
        MetadataSuggestion::query()->whereKey($stale->id)->update([
            'status' => SuggestionStatus::Accepted->value,
        ]);

        $this->assertSame(SuggestionStatus::Proposed, $stale->status, 'The in-memory copy is deliberately stale.');

        try {
            $decisions->accept($stale, $this->user());
            $this->fail('A stale acceptance must not be applied a second time.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_ALREADY_DECIDED', $refused->reason);
        }
    }

    #[Test]
    public function a_stale_rejection_cannot_overwrite_a_decision_already_recorded(): void
    {
        $track = $this->track();
        $stale = $this->suggestion($track->master_asset_id);
        $reviewer = $this->user();

        MetadataSuggestion::query()->whereKey($stale->id)->update([
            'status' => SuggestionStatus::Accepted->value,
            'decided_by' => $reviewer->id,
        ]);

        try {
            $this->decisions()->reject($stale, $reviewer);
            $this->fail('A stale rejection must not overwrite a recorded decision.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_ALREADY_DECIDED', $refused->reason);
        }

        $this->assertSame(SuggestionStatus::Accepted, $stale->fresh()->status);
    }

    #[Test]
    public function an_asset_that_is_not_a_track_master_cannot_be_accepted_into_the_catalogue(): void
    {
        // Not a gap to be filled by creating a track. A track is a catalogue
        // entry with a lineage and a promotion decision behind it; letting an
        // accepted suggestion conjure one would make a model the author of
        // catalogue entries.
        $suggestion = $this->suggestion($this->asset()->id);

        try {
            $this->decisions()->accept($suggestion, $this->user());
            $this->fail('A suggestion must not create a track.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_NO_TRACK', $refused->reason);
        }

        $this->assertSame(0, Track::query()->count());
        $this->assertSame(SuggestionStatus::Proposed, $suggestion->fresh()?->status);
    }

    #[Test]
    public function a_suggestion_is_never_applied_to_somebody_elses_track(): void
    {
        // The match is on `master_asset_id`, and this is what that buys. A
        // suggestion describes one recording; applying it to whichever track
        // happened to be found would rewrite an unrelated catalogue entry with
        // a model's opinion of a different file -- and it would look entirely
        // correct afterwards, because the row it wrote is a plausible one.
        $other = $this->track('somebody-elses-master.wav');
        $suggestion = $this->suggestion($this->asset('orphan.wav')->id);

        try {
            $this->decisions()->accept($suggestion, $this->user());
            $this->fail('A suggestion must only reach the track its asset masters.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_NO_TRACK', $refused->reason);
        }

        $this->assertSame('An older title', $other->fresh()?->title);
    }

    #[Test]
    public function accepting_nothing_is_refused_rather_than_recorded(): void
    {
        // A reviewer who cleared every field meant to reject, and recording it
        // as an acceptance that wrote nothing would leave a row claiming a
        // model's answer was used when it was not.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id, [
            'suggested_title' => null,
            'suggested_language' => null,
            'suggested_genres' => [],
        ]);

        try {
            $this->decisions()->accept($suggestion, $this->user());
            $this->fail('An acceptance carrying nothing must be refused.');
        } catch (SuggestionDecisionException $refused) {
            $this->assertSame('SUGGESTION_NOTHING_TO_ACCEPT', $refused->reason);
        }

        $this->assertSame(SuggestionStatus::Proposed, $suggestion->fresh()?->status);
    }

    #[Test]
    public function every_refusal_is_audited_with_its_code(): void
    {
        $suggestion = $this->suggestion($this->asset()->id);

        try {
            $this->decisions()->accept($suggestion, $this->user());
        } catch (SuggestionDecisionException) {
            // expected
        }

        $event = AuditEvent::query()
            ->where('action', AuditAction::SuggestionAccepted->value)
            ->latest('id')
            ->first();

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame('SUGGESTION_NO_TRACK', $event->reason);
    }

    // ------------------------------------------------------------ rejecting

    #[Test]
    public function rejecting_records_that_a_person_disagreed_and_writes_nothing(): void
    {
        // The more interesting of the two lines: a run of rejections against
        // one prompt version is how a bad prompt announces itself.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);
        $reviewer = $this->user();

        $this->decisions()->reject($suggestion, $reviewer);

        $this->assertSame(SuggestionStatus::Rejected, $suggestion->fresh()->status);
        $this->assertSame($reviewer->id, $suggestion->fresh()->decided_by);
        $this->assertSame('An older title', $track->fresh()?->title);

        $this->assertTrue(
            AuditEvent::query()->where('action', AuditAction::SuggestionRejected->value)->exists(),
        );
    }

    #[Test]
    public function a_rejected_suggestion_cannot_then_be_accepted(): void
    {
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);
        $decisions = $this->decisions();

        $decisions->reject($suggestion, $this->user());

        $this->expectException(SuggestionDecisionException::class);

        $decisions->accept($suggestion->fresh() ?? $suggestion, $this->user());
    }

    // ---------------------------------------------------------- regenerating

    #[Test]
    public function regenerating_supersedes_rather_than_rejecting(): void
    {
        // A rejection is a person saying the model was wrong, and it is
        // counted. Recording one somebody did not make would corrupt the only
        // signal the platform has about prompt quality.
        Queue::fake();

        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);
        $this->transcript($track->master_asset_id);

        $this->actingAs($this->user())
            ->post('/assets/'.$track->masterAsset?->uuid.'/enrichment/regenerate')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(SuggestionStatus::Superseded, $suggestion->fresh()?->status);
        $this->assertFalse(
            AuditEvent::query()->where('action', AuditAction::SuggestionRejected->value)->exists(),
            'Regenerating must not record a rejection nobody made.',
        );

        Queue::assertPushed(SuggestMetadataJob::class);
    }

    #[Test]
    public function regenerating_still_meets_the_assets_own_refusals(): void
    {
        // The waiting-suggestion refusal is the only one regeneration is
        // allowed past. A trashed asset is not described because a reviewer
        // pressed a button twice.
        Queue::fake();

        $track = $this->track();
        $this->suggestion($track->master_asset_id);

        // No transcript, so there is nothing to reason from.
        $this->actingAs($this->user())
            ->post('/assets/'.$track->masterAsset?->uuid.'/enrichment/regenerate')
            ->assertRedirect()
            ->assertSessionHasErrors(['enrichment' => 'ENRICHMENT_NO_EVIDENCE']);

        Queue::assertNotPushed(SuggestMetadataJob::class);
    }

    #[Test]
    public function regenerating_still_asks_the_pause_switch(): void
    {
        // Regeneration is allowed past the waiting-suggestion refusal and past
        // nothing else. It is still a paid call, so an operator who paused the
        // platform an hour ago and forgot must not have it spent by a reviewer
        // pressing a button.
        Queue::fake();

        $track = $this->track();
        $this->suggestion($track->master_asset_id);
        $this->transcript($track->master_asset_id);
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $this->actingAs($this->user())
            ->post('/assets/'.$track->masterAsset?->uuid.'/enrichment/regenerate')
            ->assertRedirect()
            ->assertSessionHasErrors(['enrichment' => 'BACKGROUND_WORK_PAUSED']);

        Queue::assertNotPushed(SuggestMetadataJob::class);

        // And the waiting suggestion is untouched: a refused regeneration must
        // not supersede the answer somebody still has to read.
        $this->assertSame(SuggestionStatus::Proposed, MetadataSuggestion::query()->latest('id')->first()?->status);
    }

    // ------------------------------------------------- the production path

    #[Test]
    public function a_catalogue_user_accepts_over_http(): void
    {
        // **WHO CALLS THIS IN PRODUCTION?** A person, through this route,
        // substituting nothing.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        $this->actingAs($this->user())
            ->post('/enrichment/suggestions/'.$suggestion->uuid.'/accept', ['title' => 'A reviewed title'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('A reviewed title', $track->fresh()?->title);
        $this->assertSame(SuggestionStatus::Accepted, $suggestion->fresh()?->status);
    }

    #[Test]
    public function a_member_may_not_decide_anything(): void
    {
        // The guard is on the route, so a future action cannot acquire the
        // surface without it, and the hidden buttons are presentation.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        foreach ([
            '/enrichment/suggestions/'.$suggestion->uuid.'/accept',
            '/enrichment/suggestions/'.$suggestion->uuid.'/reject',
            '/assets/'.$track->masterAsset?->uuid.'/enrichment/regenerate',
        ] as $route) {
            $this->actingAs($this->user(UserRole::Member))->post($route)->assertForbidden();
        }

        $this->assertSame(SuggestionStatus::Proposed, $suggestion->fresh()?->status);
        $this->assertSame('An older title', $track->fresh()?->title);
    }

    #[Test]
    public function a_refusal_reaches_the_screen_as_a_code(): void
    {
        $track = $this->track();
        $track->forceFill(['status' => TrackStatus::Released])->save();
        $suggestion = $this->suggestion($track->master_asset_id);

        $this->actingAs($this->user())
            ->post('/enrichment/suggestions/'.$suggestion->uuid.'/accept')
            ->assertRedirect()
            ->assertSessionHasErrors(['suggestion' => 'TRACK_RELEASED']);
    }

    #[Test]
    public function the_frontend_cannot_send_a_language_the_catalogue_would_refuse(): void
    {
        // The form request is convenience, never authorisation. `language_code`
        // is read by the distribution export, where a word instead of a key is
        // a rejected delivery -- so the catalogue refuses it whatever arrives
        // over HTTP.
        $track = $this->track();
        $suggestion = $this->suggestion($track->master_asset_id);

        $this->actingAs($this->user())
            ->post('/enrichment/suggestions/'.$suggestion->uuid.'/accept', ['language_code' => 'French'])
            ->assertRedirect()
            ->assertSessionHasErrors(['suggestion' => 'TRACK_LANGUAGE_NOT_A_CODE']);

        $this->assertSame('en', $track->fresh()?->language_code);
    }

    // ------------------------------------------- the catalogue editor itself

    #[Test]
    public function the_editor_writes_only_editorial_fields(): void
    {
        // Everything on the list is something a person decides. `duration_ms`
        // and `bpm` are absent because Media measured them from the file, and
        // a person typing over a measurement is a bug rather than a feature.
        $this->assertSame(
            ['title', 'language_code', 'genre_primary', 'genre_secondary'],
            SetTrackMetadata::EDITABLE,
        );

        $track = $this->track();
        $updated = $this->app->make(SetTrackMetadata::class)->handle($track, [
            'title' => 'A new title',
            'duration_ms' => 999,
            'bpm' => 200,
            'status' => TrackStatus::Released,
        ]);

        $this->assertSame('A new title', $updated->title);
        $this->assertSame(180000, $updated->duration_ms);
        $this->assertNull($updated->bpm);
        $this->assertSame(TrackStatus::Draft, $updated->status);
    }

    #[Test]
    public function an_edit_that_changes_nothing_writes_no_audit_line(): void
    {
        $track = $this->track();

        $this->app->make(SetTrackMetadata::class)->handle($track, ['bpm' => 120]);

        $this->assertFalse(
            AuditEvent::query()->where('action', AuditAction::TrackUpdated->value)->exists(),
        );
    }

    #[Test]
    public function an_empty_title_is_refused_rather_than_written(): void
    {
        $track = $this->track();

        try {
            $this->app->make(SetTrackMetadata::class)->handle($track, ['title' => '   ']);
            $this->fail('An empty title must be refused.');
        } catch (TrackMetadataException $refused) {
            $this->assertSame('TRACK_TITLE_REQUIRED', $refused->reason);
        }

        $this->assertSame('An older title', $track->fresh()?->title);
    }

    #[Test]
    public function a_cleared_genre_becomes_null_rather_than_an_empty_string(): void
    {
        // An empty string in a nullable column reads as "set to nothing" in
        // some queries and "unset" in others.
        $track = $this->track();
        $track->forceFill(['genre_primary' => 'Folk'])->save();

        $updated = $this->app->make(SetTrackMetadata::class)->handle($track, ['genre_primary' => '  ']);

        $this->assertNull($updated->genre_primary);
    }

    // ------------------------------------------------------------ fixtures

    private function decisions(): DecideSuggestion
    {
        return $this->app->make(DecideSuggestion::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(AuditAction $action): array
    {
        $event = AuditEvent::query()
            ->where('action', $action->value)
            ->where('outcome', AuditOutcome::Succeeded->value)
            ->latest('id')
            ->first();

        return $event instanceof AuditEvent && is_array($event->context) ? $event->context : [];
    }

    private function track(string $filename = 'master.wav'): Track
    {
        $asset = $this->asset($filename);

        return Track::factory()->create([
            'master_asset_id' => $asset->id,
            'title' => 'An older title',
            'language_code' => 'en',
            'duration_ms' => 180000,
            'status' => TrackStatus::Draft,
        ]);
    }

    private function asset(string $filename = 'master.wav'): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $stream);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function suggestion(?int $assetId, array $overrides = []): MetadataSuggestion
    {
        $suggestion = MetadataSuggestion::query()->create([
            'asset_id' => $assetId,
            'task' => MetadataSuggestion::TASK_TRACK_METADATA,
            'provider' => 'fake',
            'model' => 'fake-1',
            'prompt_version' => 'v1',
            'suggested_title' => 'Une chanson',
            'suggested_language' => 'fr',
            'suggested_genres' => ['Folk', 'Ambient'],
            'suggested_moods' => ['calm'],
            'suggested_themes' => ['the sea'],
            'suggested_description' => 'A quiet folk piece.',
            'confidence_basis_points' => 6000,
            'status' => SuggestionStatus::Proposed,
            'suggested_at' => now(),
        ]);

        // Applied after creation rather than merged into the literal, so the
        // create() call keeps the typed attribute list static analysis can
        // check -- a merged array is `array<string, mixed>` and tells it
        // nothing.
        //
        // **Saved, not merely filled.** `forceFill` alone leaves the override
        // in memory and the database holding the original, so a fixture asking
        // for a REJECTED row hands back an object that says REJECTED and a
        // table that says PROPOSED. Every query-side assertion then reads the
        // wrong row while every in-memory one passes.
        if ($overrides !== []) {
            $suggestion->forceFill($overrides)->save();
        }

        return $suggestion;
    }

    private function transcript(?int $assetId): Transcript
    {
        return Transcript::query()->create([
            'asset_id' => $assetId,
            'provider' => 'whisper',
            'provider_version' => '1',
            'text' => 'la la la',
            'transcribed_at' => now(),
        ]);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::query()->create([
            'name' => 'A reviewer',
            'email' => 'r-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
