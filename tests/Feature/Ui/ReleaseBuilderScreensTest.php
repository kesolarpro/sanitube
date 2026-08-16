<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\ReleaseDetailQuery;
use SaniTube\Ui\Queries\ReleaseIndexQuery;
use SaniTube\Ui\Queries\ReleasePickerQuery;
use Tests\TestCase;

/**
 * The release builder, as a surface over REL-001.
 *
 * Four claims are what these tests exist to hold, and each of them is a way
 * the screen could quietly become a second implementation of the domain:
 *
 *   - **Nothing here writes `status`.** Readiness is earned by passing
 *     validation. A controller that assigned READY would look identical from
 *     the outside and would have skipped I4 entirely.
 *   - **`actions` is presentation, never authorisation.** Every flag in the
 *     payload has a matching test that posts past the hidden button and is
 *     refused anyway.
 *   - **The policy is asked, not copied.** What a SUBMITTED release still
 *     accepts is `ReleaseMutationPolicy`'s answer, and the screen has no
 *     opinion of its own to disagree with it.
 *   - **Identifiers are read, never minted.** The builder has no path that
 *     creates one, and the payload shows only what is in force.
 */
final class ReleaseBuilderScreensTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    // --------------------------------------------------------------- access

    #[Test]
    public function every_release_screen_is_behind_authentication(): void
    {
        $release = Release::factory()->create();

        $this->get('/releases')->assertRedirect(route('login'));
        $this->get('/releases/'.$release->uuid)->assertRedirect(route('login'));
        $this->post('/releases', ['title' => 'X', 'type' => 'SINGLE'])->assertRedirect(route('login'));
    }

    #[Test]
    public function a_deactivated_account_can_neither_read_nor_change_a_release(): void
    {
        $release = Release::factory()->create();
        $user = $this->user(UserRole::Owner, 'gone@example.test');
        $user->forceFill(['is_active' => false])->save();

        // SEC-001: access is lost at the next request, not when a cookie
        // expires.
        $this->actingAs($user)->get('/releases/'.$release->uuid)->assertRedirect(route('login'));
        $this->actingAs($user)
            ->patch('/releases/'.$release->uuid, ['title' => 'Renamed'])
            ->assertRedirect(route('login'));

        $this->assertNotSame('Renamed', $release->refresh()->title);
    }

    #[Test]
    public function a_member_may_read_a_release_and_change_nothing_about_it(): void
    {
        $release = $this->releaseWithOneTrack();
        $track = $release->tracks()->firstOrFail();
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/releases')->assertOk();
        $this->actingAs($member)->get('/releases/'.$release->uuid)->assertOk();

        $this->actingAs($member)->post('/releases', ['title' => 'Mine', 'type' => 'SINGLE'])->assertForbidden();
        $this->actingAs($member)->patch('/releases/'.$release->uuid, ['title' => 'Renamed'])->assertForbidden();
        $this->actingAs($member)
            ->post('/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertForbidden();
        $this->actingAs($member)
            ->delete('/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertForbidden();
        $this->actingAs($member)
            ->post('/releases/'.$release->uuid.'/tracks/order', ['tracks' => [$track->uuid]])
            ->assertForbidden();
        $this->actingAs($member)
            ->post('/releases/'.$release->uuid.'/artists', ['artists' => [['uuid' => Artist::factory()->create()->uuid, 'role' => 'PRIMARY']]])
            ->assertForbidden();
        $this->actingAs($member)
            ->post('/releases/'.$release->uuid.'/cover', ['asset' => Asset::factory()->artwork()->verified()->create()->uuid])
            ->assertForbidden();
        $this->actingAs($member)->post('/releases/'.$release->uuid.'/ready')->assertForbidden();
        $this->actingAs($member)->post('/releases/'.$release->uuid.'/reopen')->assertForbidden();

        $release->refresh();
        $this->assertNotSame('Renamed', $release->title);
        $this->assertSame(1, $release->tracks()->count());
        $this->assertSame(ReleaseStatus::Draft, $release->status);
    }

    #[Test]
    public function a_member_is_not_offered_the_actions_either(): void
    {
        $payload = $this->detail(
            $this->releaseWithOneTrack(),
            $this->user(UserRole::Member, 'member@example.test'),
        );

        $this->assertFalse($payload['actions']['may_edit']);
        $this->assertFalse($payload['actions']['can_edit_details']);
        $this->assertFalse($payload['actions']['can_edit_tracks']);
        $this->assertFalse($payload['actions']['can_edit_artists']);
        $this->assertFalse($payload['actions']['can_edit_cover']);
        $this->assertFalse($payload['actions']['can_mark_ready']);
        $this->assertFalse($payload['actions']['can_reopen']);
    }

    #[Test]
    public function hiding_a_control_is_never_what_stops_a_request(): void
    {
        // READY: the payload offers no structural editing at all. The request
        // is refused by the domain regardless, which is the property that
        // makes the flags safe to compute for presentation.
        $release = Release::factory()->ready()->create();
        $spare = Track::factory()->ready()->create();

        $payload = $this->detail($release, $this->owner());
        $this->assertFalse($payload['actions']['can_edit_tracks']);
        $this->assertFalse($payload['actions']['can_edit_details']);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/tracks', ['track' => $spare->uuid])
            ->assertSessionHasErrors(['release' => 'RELEASE_NOT_EDITABLE']);

        $this->actingAs($this->owner())
            ->patch('/releases/'.$release->uuid, ['title' => 'Renamed behind the screen'])
            ->assertSessionHasErrors(['release' => 'RELEASE_NOT_EDITABLE']);

        $release->refresh();
        $this->assertNotSame('Renamed behind the screen', $release->title);
        $this->assertSame(1, $release->tracks()->count());
    }

    // ------------------------------------------------------------- the list

    #[Test]
    public function the_list_is_ordered_by_when_the_record_was_made(): void
    {
        // Release dates deliberately in the opposite order to creation: a back
        // catalogue is entered in whatever order the boxes come off the shelf,
        // and the list must not reshuffle as dates are filled in.
        $first = Release::factory()->create([
            'title' => 'Entered first',
            'created_at' => now()->subDays(3),
            'release_date' => '2030-01-01',
        ]);
        $second = Release::factory()->create([
            'title' => 'Entered second',
            'created_at' => now()->subDay(),
            'release_date' => '1998-01-01',
        ]);

        $rows = $this->app->make(ReleaseIndexQuery::class)->paginate()['rows'];

        $this->assertSame([$first->uuid, $second->uuid], array_column($rows, 'uuid'));
    }

    #[Test]
    public function a_release_with_no_date_says_so_rather_than_guessing_one(): void
    {
        Release::factory()->create(['release_date' => null]);

        $rows = $this->app->make(ReleaseIndexQuery::class)->paginate()['rows'];

        // Null stays null. A placeholder date would be indistinguishable from
        // a real one, on the single field readiness turns on.
        $this->assertNull($rows[0]['release_date']);
    }

    #[Test]
    public function the_list_filters_by_status_and_by_type(): void
    {
        Release::factory()->ready()->create(['title' => 'Ready one']);
        Release::factory()->album()->create(['title' => 'Draft album']);

        $query = $this->app->make(ReleaseIndexQuery::class);

        $ready = $query->paginate(['status' => 'READY'])['rows'];
        $this->assertCount(1, $ready);
        $this->assertSame('READY', $ready[0]['status']);

        $albums = $query->paginate(['type' => 'ALBUM'])['rows'];
        $this->assertCount(1, $albums);
        $this->assertSame('Draft album', $albums[0]['title']);
    }

    #[Test]
    public function the_list_counts_what_is_on_each_release(): void
    {
        Release::factory()->ready(3)->create();

        $row = $this->app->make(ReleaseIndexQuery::class)->paginate()['rows'][0];

        $this->assertSame(3, $row['track_count']);
        $this->assertSame(1, $row['artist_count']);
        $this->assertTrue($row['has_cover']);
    }

    #[Test]
    public function a_cursor_from_another_list_is_refused_rather_than_crashing(): void
    {
        Release::factory()->count(2)->create();

        // A cursor describes an ordering. One minted by a differently ordered
        // list is not a bad request to guess at — it is a wrong answer waiting
        // to be paginated through, and a 500 would be the honest failure while
        // a 422 is the useful one.
        $foreign = base64_encode(json_encode(['title' => 'x', '_pointsToNextItems' => true], JSON_THROW_ON_ERROR));

        $this->actingAs($this->owner())
            ->get('/releases?cursor='.urlencode($foreign))
            ->assertStatus(422);
    }

    #[Test]
    public function the_query_refuses_a_foreign_cursor_on_its_own(): void
    {
        // Separate from the HTTP test above, which is satisfied by the form
        // request's rule. Deleting the guard inside the query left that test
        // green, so it was proving the request and nothing else — and the
        // query is what the next caller will reach for.
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ReleaseIndexQuery::class)->paginate(
            [],
            base64_encode(json_encode(['title' => 'x', '_pointsToNextItems' => true], JSON_THROW_ON_ERROR)),
        );
    }

    #[Test]
    public function a_page_of_releases_costs_a_bounded_number_of_queries(): void
    {
        Release::factory()->count(6)->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->app->make(ReleaseIndexQuery::class)->paginate();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One page, one count per relation. Counting per row is invisible
        // until somebody has a back catalogue, which is the point at which
        // this screen starts to matter.
        $this->assertLessThanOrEqual(3, $queries, 'The release list counts per row.');
    }

    // --------------------------------------------------------- the builder

    #[Test]
    public function the_payload_shows_the_tracklist_in_delivery_order(): void
    {
        $release = Release::factory()->ready(3)->create();

        $payload = $this->detail($release, $this->owner());

        $numbers = array_column($payload['tracks'], 'track_number');
        $this->assertSame([1, 2, 3], $numbers);
    }

    #[Test]
    public function the_cover_is_described_and_never_located(): void
    {
        $release = Release::factory()->ready()->create();

        $cover = $this->detail($release, $this->owner())['cover'];

        $this->assertIsArray($cover);
        $this->assertTrue($cover['verified']);

        // The same rule the asset screens follow: what a builder needs is that
        // a cover exists and is usable, not where the bytes are.
        foreach (['path', 'disk', 'url', 'storage_path', 'source_reference'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $cover);
        }
    }

    #[Test]
    public function only_identifiers_in_force_are_shown(): void
    {
        $release = Release::factory()->create();

        $upc = $this->app->make(AssignExternalIdentifier::class)(
            entity: $release,
            type: ExternalIdentifierType::Upc,
            value: '012345678905',
            source: ExternalIdentifierSource::Manual,
        );

        $this->assertCount(1, $this->detail($release->refresh(), $this->owner())['identifiers']);

        $this->app->make(RevokeExternalIdentifier::class)($upc, 'Replaced by the distributor');

        // A revoked UPC is history. Beside a live one it would make a delivery
        // look identified when it is not.
        $this->assertSame([], $this->detail($release->refresh(), $this->owner())['identifiers']);
    }

    #[Test]
    public function whether_it_may_be_marked_ready_follows_the_domains_own_list(): void
    {
        $incomplete = Release::factory()->create(['release_date' => null]);

        $payload = $this->detail($incomplete, $this->owner());
        $this->assertNotSame([], $payload['readiness_problems']);
        $this->assertFalse($payload['actions']['can_mark_ready']);

        $complete = Release::factory()->ready()->create();
        $this->app->make(ReleaseBuilder::class)->reopen($complete);

        $payload = $this->detail($complete->refresh(), $this->owner());

        // The button is enabled by exactly the facts markReady() would accept
        // — the same list, not a second opinion about it.
        $this->assertSame([], $payload['readiness_problems']);
        $this->assertTrue($payload['actions']['can_mark_ready']);
    }

    // ---------------------------------------------------------- the actions

    #[Test]
    public function creating_a_release_makes_a_draft_and_opens_it(): void
    {
        $this->actingAs($this->owner())
            ->post('/releases', ['title' => 'Nocturnes', 'type' => 'ALBUM', 'language_code' => 'fr'])
            ->assertRedirect('/releases/'.Release::query()->firstOrFail()->uuid);

        $release = Release::query()->firstOrFail();

        // A new release is a draft and nothing else. Creating one that was
        // already ready would mean readiness had been assigned rather than
        // earned.
        $this->assertSame(ReleaseStatus::Draft, $release->status);
        $this->assertSame('Nocturnes', $release->title);
    }

    #[Test]
    public function saving_details_never_writes_the_status(): void
    {
        $release = Release::factory()->create();

        $this->actingAs($this->owner())
            ->patch('/releases/'.$release->uuid, [
                'title' => 'Nocturnes',
                'label_name' => 'Maison',
                'catalogue_number' => 'MSN-001',
                // Sent deliberately. The form request does not accept it and
                // the builder does not read it; if either changed, this would
                // be the test that noticed.
                'status' => 'LIVE',
            ])
            ->assertRedirect();

        $release->refresh();

        $this->assertSame('Nocturnes', $release->title);
        $this->assertSame('Maison', $release->label_name);
        $this->assertSame(ReleaseStatus::Draft, $release->status);
    }

    #[Test]
    public function the_same_recording_cannot_be_put_on_a_release_twice(): void
    {
        $release = $this->releaseWithOneTrack();
        $track = $release->tracks()->firstOrFail();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertSessionHasErrors(['release' => 'TRACK_ALREADY_ON_RELEASE']);

        $this->assertSame(1, $release->refresh()->tracks()->count());
    }

    #[Test]
    public function removing_a_track_closes_the_gap_it_leaves(): void
    {
        $release = Release::factory()->ready(3)->create();
        $this->app->make(ReleaseBuilder::class)->reopen($release);

        $second = $release->trackEntries()->where('track_number', 2)->firstOrFail();
        $removed = Track::query()->findOrFail($second->track_id);

        $this->actingAs($this->owner())
            ->delete('/releases/'.$release->uuid.'/tracks', ['track' => $removed->uuid])
            ->assertRedirect();

        // A tracklist running 1, 2, 4 is rejected on delivery. The renumbering
        // is the domain's, and this is the screen proving it reaches it.
        $numbers = $release->refresh()->trackEntries()->orderBy('track_number')->pluck('track_number')->all();
        $this->assertSame([1, 2], $numbers);
    }

    #[Test]
    public function reordering_a_disc_writes_the_whole_order_at_once(): void
    {
        $release = Release::factory()->ready(3)->create();
        $this->app->make(ReleaseBuilder::class)->reopen($release);

        $uuids = $release->trackEntries()
            ->with('track')
            ->orderBy('track_number')
            ->get()
            ->map(static fn ($entry): string => (string) $entry->track?->uuid)
            ->all();

        // Reversed. Sent as one order rather than a swap, because
        // UNIQUE(release_id, disc_number, track_number) collides the moment a
        // track moves into a slot another has not yet left.
        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/tracks/order', ['disc' => 1, 'tracks' => array_reverse($uuids)])
            ->assertRedirect();

        $payload = $this->detail($release->refresh(), $this->owner());

        $this->assertSame(array_reverse($uuids), array_column($payload['tracks'], 'uuid'));
        $this->assertSame([1, 2, 3], array_column($payload['tracks'], 'track_number'));
    }

    #[Test]
    public function a_release_credited_to_nobody_primary_is_refused(): void
    {
        $release = $this->releaseWithOneTrack();
        $featured = Artist::factory()->create();

        // The rule lives in the builder, not in the form request. This is the
        // only place it can be enforced, which is why it is tested from here.
        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/artists', [
                'artists' => [['uuid' => $featured->uuid, 'role' => 'FEATURED']],
            ])
            ->assertSessionHasErrors(['release' => 'PRIMARY_ARTIST_REQUIRED']);

        $this->assertSame(0, $release->refresh()->artists()->count());
    }

    #[Test]
    public function a_credit_list_is_saved_in_the_order_it_was_given(): void
    {
        $release = $this->releaseWithOneTrack();
        $lead = Artist::factory()->create(['name' => 'Lead']);
        $guest = Artist::factory()->create(['name' => 'Guest']);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/artists', [
                'artists' => [
                    ['uuid' => $lead->uuid, 'role' => 'PRIMARY'],
                    ['uuid' => $guest->uuid, 'role' => 'FEATURED'],
                ],
            ])
            ->assertRedirect();

        $credits = $this->detail($release->refresh(), $this->owner())['artists'];

        // The order is the credit: "A feat. B" and "B feat. A" are different
        // releases.
        $this->assertSame(['Lead', 'Guest'], array_column($credits, 'name'));
        $this->assertSame(['PRIMARY', 'FEATURED'], array_column($credits, 'role'));
    }

    #[Test]
    public function an_unverified_image_cannot_become_a_cover(): void
    {
        $release = $this->releaseWithOneTrack();
        $unverified = Asset::factory()->artwork()->create(['status' => AssetStatus::Stored]);

        // Refused when the mistake is made rather than at delivery, which is
        // the difference between a correction and an incident.
        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/cover', ['asset' => $unverified->uuid])
            ->assertSessionHasErrors(['release' => 'COVER_NOT_USABLE']);

        $this->assertNull($release->refresh()->cover_asset_id);
    }

    #[Test]
    public function an_unknown_track_is_refused_by_code_rather_than_a_missing_page(): void
    {
        $release = $this->releaseWithOneTrack();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/tracks', ['track' => '00000000-0000-7000-8000-000000000000'])
            ->assertSessionHasErrors(['release' => 'UNKNOWN_TRACK']);
    }

    // -------------------------------------------------------------- readiness

    #[Test]
    public function marking_an_incomplete_release_ready_is_refused_and_changes_nothing(): void
    {
        $release = Release::factory()->create(['release_date' => null]);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/ready')
            ->assertSessionHasErrors(['release' => 'RELEASE_NOT_READY']);

        $this->assertSame(ReleaseStatus::Draft, $release->refresh()->status);
    }

    #[Test]
    public function readiness_is_earned_by_passing_validation(): void
    {
        $release = Release::factory()->ready()->create();
        $this->app->make(ReleaseBuilder::class)->reopen($release);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/ready')
            ->assertRedirect();

        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);
    }

    #[Test]
    public function a_ready_release_can_be_retracted_and_a_draft_one_cannot(): void
    {
        $ready = Release::factory()->ready()->create();

        $this->actingAs($this->owner())->post('/releases/'.$ready->uuid.'/reopen')->assertRedirect();
        $this->assertSame(ReleaseStatus::Draft, $ready->refresh()->status);

        // Reopening a draft is not a no-op to wave through: it means the
        // caller believes the release is in a state it is not.
        $this->actingAs($this->owner())
            ->post('/releases/'.$ready->uuid.'/reopen')
            ->assertSessionHasErrors(['release' => 'RELEASE_NOT_REOPENABLE']);
    }

    #[Test]
    public function a_committed_release_cannot_be_retracted_from_the_screen(): void
    {
        $submitted = Release::factory()->submitted()->create();

        $payload = $this->detail($submitted, $this->owner());
        $this->assertFalse($payload['actions']['can_reopen']);

        $this->actingAs($this->owner())
            ->post('/releases/'.$submitted->uuid.'/reopen')
            ->assertSessionHasErrors(['release' => 'RELEASE_NOT_REOPENABLE']);

        $this->assertSame(ReleaseStatus::Submitted, $submitted->refresh()->status);
    }

    #[Test]
    public function a_refusal_carries_its_code_and_not_the_domains_sentence(): void
    {
        $release = Release::factory()->ready()->create();
        $spare = Track::factory()->ready()->create();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/tracks', ['track' => $spare->uuid]);

        $errors = session('errors');
        $this->assertNotNull($errors);

        // The code travels; the interface translates it. The domain's English
        // is written for a developer reading a log, and it names statuses.
        $this->assertSame('RELEASE_NOT_EDITABLE', $errors->first('release'));
    }

    // --------------------------------------------------------- the pickers

    #[Test]
    public function the_pickers_are_behind_the_write_role(): void
    {
        $release = $this->releaseWithOneTrack();
        $member = $this->user(UserRole::Member, 'member@example.test');

        // They exist to fill in editing forms. A reader who cannot add a track
        // has no use for the list of tracks they could add.
        $this->actingAs($member)->get('/releases/options/artists')->assertForbidden();
        $this->actingAs($member)->get('/releases/options/artwork')->assertForbidden();
        $this->actingAs($member)->get('/releases/'.$release->uuid.'/options/tracks')->assertForbidden();
    }

    #[Test]
    public function the_pickers_are_behind_authentication(): void
    {
        // Its own test rather than a line at the end of the previous one:
        // `actingAs` persists for the rest of a test, so a "guest" assertion
        // after it is really the same signed-in user again.
        $this->get('/releases/options/artists')->assertRedirect(route('login'));
        $this->get('/releases/options/artwork')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_track_picker_offers_only_tracks_that_could_be_added(): void
    {
        $release = $this->releaseWithOneTrack();
        $onRelease = $release->tracks()->firstOrFail();
        $available = Track::factory()->ready()->create(['title' => 'Available']);
        $archived = Track::factory()->create(['title' => 'Archived', 'status' => TrackStatus::Archived]);

        $rows = $this->app->make(ReleasePickerQuery::class)->tracks($release, '');
        $uuids = array_column($rows, 'uuid');

        $this->assertContains($available->uuid, $uuids);

        // Offering a choice the builder always refuses is a bug with a nice
        // error message on it.
        $this->assertNotContains($onRelease->uuid, $uuids);
        $this->assertNotContains($archived->uuid, $uuids);
    }

    #[Test]
    public function the_artwork_picker_offers_only_covers_the_builder_would_accept(): void
    {
        $usable = Asset::factory()->artwork()->verified()->create(['original_filename' => 'front.jpg']);
        $unverified = Asset::factory()->artwork()->create([
            'original_filename' => 'draft.jpg',
            'status' => AssetStatus::Stored,
        ]);
        $master = Asset::factory()->verified()->create(['original_filename' => 'master.wav']);

        $uuids = array_column($this->app->make(ReleasePickerQuery::class)->artwork(''), 'uuid');

        $this->assertContains($usable->uuid, $uuids);
        $this->assertNotContains($unverified->uuid, $uuids);
        $this->assertNotContains($master->uuid, $uuids);
    }

    #[Test]
    public function a_picker_never_returns_more_than_its_cap(): void
    {
        Artist::factory()->count(ReleasePickerQuery::LIMIT + 5)->create();

        $rows = $this->app->make(ReleasePickerQuery::class)->artists('');

        // Bounded on purpose. A picker that returns a catalogue is a picker
        // that hangs a browser on an installation with real data in it.
        $this->assertCount(ReleasePickerQuery::LIMIT, $rows);
    }

    #[Test]
    public function a_wildcard_typed_into_a_picker_is_treated_as_text(): void
    {
        Artist::factory()->create(['name' => 'Chopin']);
        Artist::factory()->create(['name' => 'Debussy']);

        // `%` is a wildcard to LIKE and an ordinary character to the person
        // typing it. Left as syntax it matches the entire catalogue, and the
        // cap silently becomes the whole answer.
        $rows = $this->app->make(ReleasePickerQuery::class)->artists('%');

        $this->assertSame([], $rows);
    }

    #[Test]
    public function a_picker_search_finds_by_name(): void
    {
        Artist::factory()->create(['name' => 'Chopin']);
        Artist::factory()->create(['name' => 'Debussy']);

        $rows = $this->app->make(ReleasePickerQuery::class)->artists('hopi');

        $this->assertCount(1, $rows);
        $this->assertSame('Chopin', $rows[0]['name']);
    }

    // ------------------------------------------------------------ navigation

    #[Test]
    public function releases_is_reachable_from_the_navigation(): void
    {
        $tree = (new NavigationTree)->for($this->owner());

        $releases = null;

        foreach ($tree as $item) {
            if ($item['key'] === 'releases') {
                $releases = $item;
            }
        }

        $this->assertNotNull($releases);
        $this->assertTrue($releases['available']);
        $this->assertSame('/releases', $releases['href']);
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function detail(Release $release, User $viewer): array
    {
        return $this->app->make(ReleaseDetailQuery::class)->forRelease($release, $viewer);
    }

    private function releaseWithOneTrack(): Release
    {
        $release = Release::factory()->create();

        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1,
            'track_number' => 1,
            'is_focus_track' => true,
        ]);

        return $release->refresh();
    }

    /**
     * Memoised: several tests act as the owner more than once, and a second
     * `User::factory()` collides on the unique email.
     */
    private function owner(): User
    {
        return $this->owner ??= $this->user(UserRole::Owner, 'owner@example.test');
    }

    private function user(UserRole $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true]);
    }
}
