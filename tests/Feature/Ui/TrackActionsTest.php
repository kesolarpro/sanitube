<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Models\TrackArtist;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Localization\ContentLanguage;
use Tests\TestCase;

/**
 * The two things a person can do to a track, from a browser.
 *
 * CAT-002 exists because neither was possible. Promotion creates a DRAFT
 * track, I3 refuses READY without a primary artist, and nothing anywhere gave
 * a person a way to supply one — so a label who imported a library could
 * promote a candidate and then stop, with every release built from those
 * tracks failing validation on an issue whose fix was not reachable from any
 * screen.
 *
 * Most of what follows is about the two things that must stay true now that
 * the surface exists: **a MEMBER cannot write**, and **readiness is earned**.
 */
final class TrackActionsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- credits

    #[Test]
    public function an_owner_can_credit_a_track(): void
    {
        $track = $this->track();
        $artist = Artist::factory()->create(['name' => 'The Label']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
            ])
            ->assertRedirect();

        $this->assertSame(1, $track->refresh()->primaryArtists()->count());
    }

    #[Test]
    public function the_list_replaces_rather_than_adds_to_what_was_there(): void
    {
        // The order *is* the credit — "A feat. B" is not "B feat. A" — so the
        // list is sent whole. A caller that expected a merge would silently
        // accumulate credits nobody removed.
        $track = $this->track();
        $first = Artist::factory()->create(['name' => 'First']);
        $second = Artist::factory()->create(['name' => 'Second']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [
                    ['uuid' => $first->uuid, 'role' => 'PRIMARY'],
                    ['uuid' => $second->uuid, 'role' => 'FEATURED'],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $second->uuid, 'role' => 'PRIMARY']],
            ])
            ->assertRedirect();

        $credits = TrackArtist::query()->where('track_id', $track->getKey())->get();

        $this->assertCount(1, $credits);
        $this->assertSame($second->getKey(), $credits->first()?->artist_id);
        $this->assertSame(TrackArtistRole::Primary, $credits->first()?->role);
    }

    #[Test]
    public function one_person_can_hold_two_roles_on_the_same_recording(): void
    {
        // `track_artist` is unique on (track, artist, role) precisely so this
        // is expressible. Writing credits through `sync()` — which is keyed by
        // artist — would silently keep only the last role given, and a
        // performer who also remixed their own record would lose a credit
        // without anything being refused.
        $track = $this->track();
        $artist = Artist::factory()->create(['name' => 'Both']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [
                    ['uuid' => $artist->uuid, 'role' => 'PRIMARY'],
                    ['uuid' => $artist->uuid, 'role' => 'REMIXER'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, TrackArtist::query()->where('track_id', $track->getKey())->count());
    }

    #[Test]
    public function the_same_person_in_the_same_role_twice_is_one_credit(): void
    {
        // A duplicated line in a form, not an error worth a 500. The unique
        // index would refuse the second row.
        $track = $this->track();
        $artist = Artist::factory()->create(['name' => 'Once']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [
                    ['uuid' => $artist->uuid, 'role' => 'PRIMARY'],
                    ['uuid' => $artist->uuid, 'role' => 'PRIMARY'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, TrackArtist::query()->where('track_id', $track->getKey())->count());
    }

    #[Test]
    public function credits_with_no_primary_are_refused_with_a_code(): void
    {
        $track = $this->track();
        $artist = Artist::factory()->create(['name' => 'Guest']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $artist->uuid, 'role' => 'FEATURED']],
            ])
            ->assertSessionHasErrors(['track' => 'TRACK_PRIMARY_ARTIST_REQUIRED']);

        $this->assertSame(0, TrackArtist::query()->where('track_id', $track->getKey())->count());
    }

    #[Test]
    public function a_released_recording_keeps_the_credits_it_was_delivered_with(): void
    {
        // A store is serving the recording under the metadata it was given.
        // Editing the local row would only make the two disagree without
        // changing anything a listener sees.
        $track = $this->readyTrack();
        $track->forceFill(['status' => TrackStatus::Released])->save();

        $other = Artist::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $other->uuid, 'role' => 'PRIMARY']],
            ])
            ->assertSessionHasErrors(['track' => 'TRACK_NOT_CREDITABLE']);
    }

    #[Test]
    public function an_artist_that_does_not_exist_is_refused_by_name(): void
    {
        $track = $this->track();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => '01a00000-0000-7000-8000-000000000000', 'role' => 'PRIMARY']],
            ])
            ->assertSessionHasErrors(['track' => 'UNKNOWN_ARTIST']);
    }

    // ----------------------------------------------------------- readiness

    #[Test]
    public function a_credited_track_can_be_marked_ready(): void
    {
        $track = $this->readyTrack();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/ready')
            ->assertRedirect();

        $this->assertSame(TrackStatus::Ready, $track->refresh()->status);
    }

    #[Test]
    public function readiness_is_earned_and_never_assigned(): void
    {
        // The load-bearing claim. A track with no credits is refused, and the
        // refusal names a code rather than a sentence — the screen already
        // renders the problems from the same `readinessProblems()` this throws
        // on, and a second copy in a flash message could disagree with it.
        $track = $this->track();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/ready')
            ->assertSessionHasErrors(['track' => 'TRACK_NOT_READY']);

        $this->assertSame(TrackStatus::Draft, $track->refresh()->status);
    }

    // ------------------------------------------------------- authorisation

    #[Test]
    public function a_member_may_read_a_track_and_write_nothing(): void
    {
        $track = $this->readyTrack();
        $member = $this->user(UserRole::Member, 'member@example.test');
        $artist = Artist::factory()->create(['name' => 'Nobody']);

        $this->actingAs($member)->get('/catalog/tracks/'.$track->uuid)->assertOk();

        // Posted past the interface. The buttons are absent for a MEMBER, and
        // absent buttons are presentation — the guard is the route.
        $this->actingAs($member)
            ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->post('/catalog/tracks/'.$track->uuid.'/ready')
            ->assertForbidden();

        $this->assertSame(TrackStatus::Draft, $track->refresh()->status);
    }

    #[Test]
    public function the_screen_offers_a_member_nothing_to_press(): void
    {
        $track = $this->readyTrack();

        $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->get('/catalog/tracks/'.$track->uuid)
            ->assertInertia(fn ($page) => $page
                ->where('track.actions.may_edit', false)
                ->where('track.actions.can_edit_credits', false)
                ->where('track.actions.can_mark_ready', false));
    }

    // ------------------------------------------------------------- payload

    #[Test]
    public function the_screen_is_told_what_is_outstanding_in_codes(): void
    {
        $track = $this->track();

        $this->actingAs($this->user())
            ->get('/catalog/tracks/'.$track->uuid)
            ->assertInertia(fn ($page) => $page
                ->where('track.readiness_problems.0.code', 'TRACK_PRIMARY_ARTIST_REQUIRED')
                ->where('track.readiness_problems.0.severity', 'ERROR')
                ->where('track.actions.can_mark_ready', false));
    }

    #[Test]
    public function the_payload_never_carries_the_english(): void
    {
        $track = $this->track();

        $response = $this->actingAs($this->user())->get('/catalog/tracks/'.$track->uuid);

        // The message is reachable for a log and absent from anything that
        // travels — the asymmetry REL-002 introduced, held to on this screen.
        $response->assertDontSee('At least one PRIMARY artist is required.', escape: false);
    }

    #[Test]
    public function a_track_that_passes_is_offered_the_action(): void
    {
        $track = $this->readyTrack();

        $this->actingAs($this->user())
            ->get('/catalog/tracks/'.$track->uuid)
            ->assertInertia(fn ($page) => $page
                ->where('track.readiness_problems', [])
                ->where('track.actions.can_mark_ready', true));
    }

    // ------------------------------------------------------------ fixtures

    private function track(): Track
    {
        $bytes = 'the recording itself';

        $master = Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'masters/'.bin2hex(random_bytes(4)).'.wav',
            'original_filename' => 'master.wav',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'audio/wav',
        ]);

        return Track::factory()->create([
            'title' => 'Night Bus',
            'status' => TrackStatus::Draft,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
            'master_asset_id' => $master->getKey(),
        ]);
    }

    /**
     * A track with nothing outstanding, still in DRAFT.
     */
    private function readyTrack(): Track
    {
        $track = $this->track();

        TrackArtist::query()->create([
            'track_id' => $track->getKey(),
            'artist_id' => Artist::factory()->create(['name' => 'The Label'])->getKey(),
            'role' => TrackArtistRole::Primary,
            'position' => 1,
        ]);

        return $track->refresh();
    }

    /**
     * The signed-in person, created once per test and *persisted* with a role.
     *
     * `forceFill()` without a save leaves the role in memory only, which is
     * enough for a single `actingAs()` and silently wrong the moment a test
     * signs in twice — the second call reads the row back with whatever the
     * column defaulted to and the request is refused for a reason the test
     * never intended to exercise.
     */
    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Owner', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
