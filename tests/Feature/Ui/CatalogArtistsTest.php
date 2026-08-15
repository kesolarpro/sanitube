<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Models\TrackArtist;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\ArtistDetailQuery;
use SaniTube\Ui\Queries\ArtistIndexQuery;
use Tests\TestCase;

/**
 * The artist list and detail, holding the same guarantees the track screens
 * established: active identifiers only, filters refused rather than ignored,
 * a cursor that carries no internal key, and a cost that does not grow per row.
 */
final class CatalogArtistsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_artist_list_is_behind_authentication(): void
    {
        $this->get('/catalog/artists')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_artists_for_a_signed_in_person(): void
    {
        Artist::factory()->count(3)->create();

        $this->actingAs($this->user())
            ->get('/catalog/artists')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $this->assertSame('Catalog/Artists/Index', $page->toArray()['component']);
                $this->assertCount(3, $page->toArray()['props']['page']['rows']);
            });
    }

    #[Test]
    public function an_artist_is_reachable_by_uuid_and_not_by_its_internal_key(): void
    {
        $artist = Artist::factory()->create();

        $user = $this->user();

        $this->actingAs($user)->get('/catalog/artists/'.$artist->uuid)->assertOk();
        $this->actingAs($user)->get('/catalog/artists/'.$artist->getKey())->assertNotFound();
    }

    #[Test]
    public function listing_more_artists_does_not_cost_more_queries(): void
    {
        // Track and release counts are aggregated in the list query. Counted
        // per row they would be two round trips per artist.
        Artist::factory()->count(2)->create();
        $small = $this->queriesForIndex();

        Artist::factory()->count(10)->create();
        $larger = $this->queriesForIndex();

        $this->assertSame($small, $larger, sprintf('Cost grew from %d to %d queries.', $small, $larger));
    }

    #[Test]
    public function the_detail_payload_carries_no_internal_key(): void
    {
        $artist = Artist::factory()->create();

        $encoded = (string) json_encode(app(ArtistDetailQuery::class)->forArtist($artist));

        foreach (['"id"', '"artist_id"', '"track_id"'] as $key) {
            $this->assertStringNotContainsString($key, $encoded, sprintf('%s reached the browser.', $key));
        }
    }

    #[Test]
    public function a_revoked_identifier_is_not_presented_as_current_identity(): void
    {
        $artist = Artist::factory()->create();
        $this->identifier($artist, 'ISNI-REVOKED', active: false);
        $this->identifier($artist, 'ISNI-CURRENT');

        $values = array_column(app(ArtistDetailQuery::class)->forArtist($artist)['identifiers'], 'value');

        $this->assertSame(['ISNI-CURRENT'], $values);

        // And the revoked row is still there for audit code to find.
        $this->assertSame(1, ExternalIdentifier::query()->revoked()->count());
    }

    #[Test]
    public function tracks_shown_reports_what_was_actually_returned(): void
    {
        // The previous version of this test only asserted the keys existed and
        // that a fresh artist was not truncated — which passed happily while
        // `tracks_shown` reported the cap for every artist under it. An artist
        // with one track claimed fifty were shown, the exact opposite of what
        // the field exists to say.
        foreach ([0, 1, 49] as $credited) {
            $artist = $this->artistWithTracks($credited);
            $detail = app(ArtistDetailQuery::class)->forArtist($artist);

            $this->assertSame($credited, $detail['tracks_shown'], sprintf('with %d tracks', $credited));
            $this->assertCount($credited, $detail['tracks']);
            $this->assertFalse($detail['tracks_truncated'], sprintf('with %d tracks', $credited));
        }
    }

    #[Test]
    public function exactly_fifty_tracks_is_shown_in_full_and_not_marked_truncated(): void
    {
        // The boundary. Fifty is the cap, so fifty is complete — flagging it as
        // truncated would send a reader looking for a fifty-first track that
        // does not exist.
        $artist = $this->artistWithTracks(50);
        $detail = app(ArtistDetailQuery::class)->forArtist($artist);

        $this->assertSame(50, $detail['tracks_shown']);
        $this->assertFalse($detail['tracks_truncated']);
    }

    #[Test]
    public function beyond_the_cap_the_list_is_capped_and_flagged(): void
    {
        $artist = $this->artistWithTracks(51);
        $detail = app(ArtistDetailQuery::class)->forArtist($artist);

        $this->assertSame(50, $detail['tracks_shown']);
        $this->assertCount(50, $detail['tracks']);
        $this->assertTrue($detail['tracks_truncated']);
        $this->assertSame(51, $detail['track_count']);
    }

    #[Test]
    public function the_capped_selection_is_the_same_on_every_load(): void
    {
        // A bare LIMIT with no ORDER BY returns whatever the engine finds
        // convenient, so the same artist can show a different fifty on two
        // consecutive loads — and the four engines this platform runs on will
        // disagree with each other as well.
        $artist = $this->artistWithTracks(60);
        $query = app(ArtistDetailQuery::class);

        $first = array_column($query->forArtist($artist)['tracks'], 'uuid');
        $second = array_column($query->forArtist($artist->fresh())['tracks'], 'uuid');

        $this->assertCount(50, $first);
        $this->assertSame($first, $second, 'The capped selection changed between two identical loads.');

        // And it is the ordering the read model claims: title, then UUID.
        $titles = array_column($query->forArtist($artist)['tracks'], 'title');
        $sorted = $titles;
        sort($sorted);

        $this->assertSame($sorted, $titles, 'The capped selection is not ordered by title.');
    }

    #[Test]
    public function a_credited_track_appears_on_the_artist(): void
    {
        $track = Track::factory()->ready()->create();
        $artist = $track->primaryArtists()->firstOrFail();

        $detail = app(ArtistDetailQuery::class)->forArtist($artist);

        $this->assertSame([$track->uuid], array_column($detail['tracks'], 'uuid'));
        $this->assertSame(1, $detail['track_count']);
    }

    // ------------------------------------------------- invalid filters are 422

    #[Test]
    public function an_invalid_status_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/artists?status=NOT_A_STATUS')->assertStatus(422);
    }

    #[Test]
    public function an_invalid_type_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/artists?type=ORCHESTRA')->assertStatus(422);
    }

    #[Test]
    public function an_invalid_country_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/artists?country=FRANCE')->assertStatus(422);
    }

    #[Test]
    public function a_malformed_cursor_is_refused_rather_than_crashing(): void
    {
        $user = $this->user();

        foreach (['not-base64!!', base64_encode('{"id":5}')] as $cursor) {
            $this->actingAs($user)->get('/catalog/artists?cursor='.urlencode($cursor))->assertStatus(422);
        }
    }

    #[Test]
    public function an_unrecognised_filter_is_refused_at_the_read_model_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ArtistIndexQuery::class)->paginate(['status' => "ACTIVE' OR 1=1 --"]);
    }

    #[Test]
    public function valid_filters_narrow_the_list_and_are_echoed_canonically(): void
    {
        Artist::factory()->count(2)->create(['status' => ArtistStatus::Active]);
        Artist::factory()->count(3)->create(['status' => ArtistStatus::Archived]);

        $this->actingAs($this->user())
            ->get('/catalog/artists?status='.ArtistStatus::Active->value)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertCount(2, $props['page']['rows']);
                $this->assertSame(ArtistStatus::Active->value, $props['filters']['status']);
                $this->assertNull($props['filters']['type']);
            });
    }

    // ------------------------------------------------------------ pagination

    #[Test]
    public function the_cursor_carries_no_internal_key_and_pages_do_not_repeat(): void
    {
        Artist::factory()->count(5)->create();

        $seen = [];
        $cursor = null;

        do {
            $page = app(ArtistIndexQuery::class)->paginate([], $cursor, 2);

            if ($cursor === null) {
                $this->assertIsString($page['next_cursor']);
                $decoded = (string) base64_decode((string) $page['next_cursor'], true);
                $this->assertStringNotContainsString('"id"', $decoded);
                $this->assertStringContainsString('name', $decoded);
            }

            foreach (array_column($page['rows'], 'uuid') as $uuid) {
                $this->assertNotContains($uuid, $seen, 'An artist appeared on two pages.');
                $seen[] = $uuid;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
    }

    // --------------------------------------------------------------- helpers

    /**
     * An artist credited on a known number of tracks, with distinct titles so
     * the ordering assertion has something to order.
     */
    private function artistWithTracks(int $count): Artist
    {
        $artist = Artist::factory()->create();

        for ($index = 0; $index < $count; $index++) {
            // Titles deliberately uncorrelated with insertion order. A
            // monotonic title would come out sorted under *any* key-based
            // ordering, so the ordering assertion would pass against a broken
            // implementation — which is exactly what happened when this
            // fixture counted downwards.
            $track = Track::factory()->create([
                'title' => sprintf('Track %03d', ($index * 37) % 997),
            ]);

            TrackArtist::query()->create([
                'track_id' => $track->getKey(),
                'artist_id' => $artist->getKey(),
                'role' => TrackArtistRole::Primary,
                'position' => 1,
            ]);
        }

        return $artist;
    }

    private function identifier(Artist $artist, string $value, bool $active = true): void
    {
        ExternalIdentifier::query()->create([
            'identifiable_type' => $artist->getMorphClass(),
            'identifiable_id' => $artist->getKey(),
            'type' => ExternalIdentifierType::Isni,
            'namespace' => $active ? '' : 'superseded',
            'value' => $value,
            'is_authoritative' => true,
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
            'active_marker' => $active ? ExternalIdentifier::ACTIVE : null,
        ]);
    }

    private function queriesForIndex(): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        app(ArtistIndexQuery::class)->paginate();

        return $count;
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
