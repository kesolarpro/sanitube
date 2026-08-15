<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\TrackDetailQuery;
use SaniTube\Ui\Queries\TrackIndexQuery;
use Tests\TestCase;

/**
 * The track list and the track detail screen.
 *
 * Two things are being defended. The first is cost: a catalogue screen that
 * issues a query per row is fine on a demo and unusable at the nine hundred
 * tracks this platform exists to manage. The second is leakage: a detail
 * endpoint is where a disk name, a bucket or an internal key escapes, and it
 * escapes quietly.
 */
final class CatalogTracksTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_catalogue_is_behind_authentication(): void
    {
        $this->get('/catalog/tracks')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_tracks_for_a_signed_in_person(): void
    {
        Track::factory()->ready()->count(3)->create();

        $this->actingAs($this->user())
            ->get('/catalog/tracks')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $this->assertSame('Catalog/Tracks/Index', $page->toArray()['component']);
                $this->assertCount(3, $page->toArray()['props']['page']['rows']);
            });
    }

    #[Test]
    public function a_track_can_be_opened_by_uuid(): void
    {
        $track = Track::factory()->ready()->create();

        $this->actingAs($this->user())
            ->get('/catalog/tracks/'.$track->uuid)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Tracks/Show'));
    }

    #[Test]
    public function a_track_cannot_be_reached_by_its_internal_key(): void
    {
        // Route binding is on the UUID, so counting upwards finds nothing. A
        // sequential key in a URL is an invitation to enumerate the catalogue.
        $track = Track::factory()->ready()->create();

        $this->actingAs($this->user())
            ->get('/catalog/tracks/'.$track->getKey())
            ->assertNotFound();
    }

    // ------------------------------------------------------------ the cost

    #[Test]
    public function listing_more_rows_does_not_cost_more_queries(): void
    {
        // The N+1 guard for the list. Artists and the ISRC are relations, and
        // rendering them lazily would be three queries per row.
        Track::factory()->ready()->count(2)->create();
        $small = $this->queriesForIndex();

        Track::factory()->ready()->count(10)->create();
        $larger = $this->queriesForIndex();

        $this->assertSame(
            $small,
            $larger,
            sprintf('The list cost grew from %d to %d queries as rows were added.', $small, $larger),
        );
    }

    // ------------------------------------------------------------- leakage

    #[Test]
    public function the_detail_screen_never_exposes_storage_location_or_internal_keys(): void
    {
        // The assertion that matters most here. A storage layout published in
        // page source is a storage layout that cannot be changed afterwards.
        $track = Track::factory()->ready()->create();

        $detail = app(TrackDetailQuery::class)->forTrack($track);
        $encoded = (string) json_encode($detail);

        foreach (['disk', 'path', 'bucket', 'original_filename', 'failure_message'] as $forbidden) {
            $this->assertStringNotContainsString(
                sprintf('"%s"', $forbidden),
                $encoded,
                sprintf('[%s] reached the browser from the track detail.', $forbidden),
            );
        }

        // No internal key, under any of the names one would arrive as.
        foreach (['"id"', '"track_id"', '"asset_id"', '"master_asset_id"', '"composition_id"'] as $key) {
            $this->assertStringNotContainsString($key, $encoded, sprintf('%s reached the browser.', $key));
        }

        // And the identity it *does* carry is the public one.
        $this->assertSame($track->uuid, $detail['uuid']);
    }

    #[Test]
    public function the_master_checksum_is_abbreviated_rather_than_published_in_full(): void
    {
        $track = Track::factory()->ready()->create();

        $detail = app(TrackDetailQuery::class)->forTrack($track);
        $master = $detail['master'];

        $this->assertIsArray($master);
        $this->assertSame(12, strlen((string) $master['checksum_short']));
        $this->assertStringStartsWith((string) $master['checksum_short'], (string) $track->masterAsset?->sha256);
    }

    // ------------------------------------------------------------- filtering

    #[Test]
    public function filtering_by_status_narrows_the_list(): void
    {
        Track::factory()->ready()->count(2)->create();
        Track::factory()->count(3)->create(['status' => TrackStatus::Draft]);

        $ready = app(TrackIndexQuery::class)->paginate(['status' => TrackStatus::Ready->value]);

        $this->assertCount(2, $ready['rows']);

        foreach ($ready['rows'] as $row) {
            $this->assertSame(TrackStatus::Ready->value, $row['status']);
        }
    }

    #[Test]
    public function an_unrecognised_filter_value_narrows_nothing_rather_than_reaching_the_database(): void
    {
        // A hand-edited query string must not become a SQL surprise, and — the
        // subtler half — must not silently return everything while the form
        // still looks filtered.
        Track::factory()->ready()->count(2)->create();

        $page = app(TrackIndexQuery::class)->paginate(['status' => "NOT_A_STATUS' OR 1=1 --"]);

        $this->assertCount(2, $page['rows']);
    }

    #[Test]
    public function searching_matches_a_title(): void
    {
        Track::factory()->ready()->create(['title' => 'Midnight Ferry']);
        Track::factory()->ready()->create(['title' => 'Something Else']);

        $page = app(TrackIndexQuery::class)->paginate(['search' => 'Ferry']);

        $this->assertCount(1, $page['rows']);
        $this->assertSame('Midnight Ferry', $page['rows'][0]['title']);
    }

    #[Test]
    public function a_search_wildcard_is_treated_as_text_rather_than_as_a_pattern(): void
    {
        // Otherwise searching for "%" returns the whole catalogue, which looks
        // like a broken filter rather than like a match.
        Track::factory()->ready()->count(3)->create();

        $page = app(TrackIndexQuery::class)->paginate(['search' => '%']);

        $this->assertCount(0, $page['rows']);
    }

    // ------------------------------------------------------------ pagination

    #[Test]
    public function pagination_is_by_cursor_and_the_cursor_carries_no_internal_key(): void
    {
        Track::factory()->ready()->count(5)->create();

        $page = app(TrackIndexQuery::class)->paginate([], null, 2);

        $this->assertCount(2, $page['rows']);
        $this->assertIsString($page['next_cursor']);

        // Laravel encodes the ordering columns into the cursor, and that string
        // travels in the URL. Ordering by `(title, uuid)` rather than by `id`
        // is what keeps internal keys out of every "next page" link.
        $decoded = (string) base64_decode($page['next_cursor'], true);

        $this->assertStringNotContainsString('"id"', $decoded);
        $this->assertStringContainsString('uuid', $decoded);
    }

    #[Test]
    public function the_second_page_continues_rather_than_repeating(): void
    {
        Track::factory()->ready()->count(5)->create();

        $first = app(TrackIndexQuery::class)->paginate([], null, 2);
        $second = app(TrackIndexQuery::class)->paginate([], $first['next_cursor'], 2);

        $seen = array_column($first['rows'], 'uuid');
        $next = array_column($second['rows'], 'uuid');

        $this->assertCount(2, $next);
        $this->assertSame([], array_intersect($seen, $next), 'A row appeared on two consecutive pages.');
    }

    // --------------------------------------------------------------- helpers

    private function queriesForIndex(): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        app(TrackIndexQuery::class)->paginate();

        return $count;
    }

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
