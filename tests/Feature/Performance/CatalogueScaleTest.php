<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * The screens must not get slower as the catalogue gets bigger.
 *
 * PERF-001. The platform is being built for a catalogue of roughly nine
 * hundred recordings, and the question that decides whether it is usable is not
 * "how fast is this page" — it is "does this page cost more when there is more
 * in it". A screen that runs one query for ten tracks and nine hundred for nine
 * hundred is fine in every test written against a fixture of three.
 *
 * **So this measures the same screens twice, at two sizes, and asserts the
 * numbers are the same.** An absolute threshold would need updating whenever a
 * legitimate column was added and would say nothing about growth. Equality
 * across sizes is the property that actually matters, and it fails loudly the
 * first time somebody loops a query over rows.
 *
 * Measured when this was written: every index screen is 1–3 queries and a
 * payload identical at both sizes, because they all page with a cursor and
 * eager-load their relations. The dashboard is about thirty queries — one per
 * aggregate it displays, none of them per-row — and also identical at both
 * sizes.
 */
final class CatalogueScaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Small enough to be quick, large enough that an N+1 over a *page* of rows
     * shows up: both sizes exceed any page size in the application.
     */
    private const SMALL = 60;

    private const LARGE = 400;

    /**
     * Loose on purpose. Every index screen measured between one and three
     * queries when this was written; an N+1 over a page of rows is dozens.
     */
    private const INDEX_QUERY_CEILING = 10;

    /**
     * @var list<string>
     */
    private const SCREENS = [
        '/',
        '/catalog/tracks',
        '/catalog/artists',
        '/catalog/assets',
        '/catalog/contributors',
        '/releases',
        '/ingestion/candidates',
        '/ingestion/batches',
        '/duplicates',
    ];

    #[Test]
    public function no_screen_costs_more_queries_because_the_catalogue_is_larger(): void
    {
        $small = $this->measureAt(self::SMALL);
        $large = $this->measureAt(self::LARGE);

        foreach (self::SCREENS as $screen) {
            $this->assertSame(
                $small[$screen]['queries'],
                $large[$screen]['queries'],
                sprintf(
                    '[%s] ran %d queries with %d tracks and %d with %d. Something is looping a query over rows.',
                    $screen,
                    $small[$screen]['queries'],
                    self::SMALL,
                    $large[$screen]['queries'],
                    self::LARGE,
                ),
            );
        }
    }

    #[Test]
    public function no_screen_sends_a_larger_payload_because_the_catalogue_is_larger(): void
    {
        // The other half of the same claim. A screen can hold its query count
        // and still serialise every row it fetched — which is how an Inertia
        // response becomes several megabytes and the browser becomes the
        // bottleneck instead of the database.
        $small = $this->measureAt(self::SMALL);
        $large = $this->measureAt(self::LARGE);

        foreach (self::SCREENS as $screen) {
            $growth = $large[$screen]['bytes'] - $small[$screen]['bytes'];

            // A small tolerance rather than equality, and the reason is the
            // dashboard: it displays counts, and `400` is two characters more
            // than `60`. That is a page reporting a bigger catalogue, not a
            // page serialising one. A kilobyte is far more than any number of
            // digits can account for and far less than one extra row of
            // catalogue data.
            $this->assertLessThan(1024, $growth, sprintf(
                '[%s] grew by %d bytes between %d and %d tracks. The page is not bounded.',
                $screen,
                $growth,
                self::SMALL,
                self::LARGE,
            ));
        }
    }

    /**
     * A ceiling per screen, because growth-equality cannot see this.
     *
     * **Found by mutating a query rather than by reasoning.** Removing the
     * eager loading from the track list — a textbook N+1 — did not fail the two
     * tests above, and the reason is worth stating: the list pages with a
     * cursor, so an N+1 runs once per *row on the page*, and that number is the
     * same whether the catalogue holds sixty tracks or four hundred. Equality
     * across catalogue sizes proves the page is bounded; it says nothing about
     * what the page costs per row inside that bound.
     *
     * So this is an absolute ceiling, and it is deliberately loose. A screen
     * that legitimately gains a relation goes from three queries to four. A
     * screen that stops eager-loading goes to one per row on the page, which is
     * an order of magnitude past this number.
     */
    #[Test]
    public function no_index_screen_runs_a_query_for_each_row_it_shows(): void
    {
        $this->seedCatalogue(self::SMALL);

        $user = $this->user();

        foreach (self::SCREENS as $screen) {
            if ($screen === '/') {
                // The dashboard is a different shape: about thirty distinct
                // aggregates, one per figure it displays, none per row. Held by
                // the growth test above and by the schema test below.
                continue;
            }

            $this->app->forgetScopedInstances();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($user)->get($screen)->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThanOrEqual(self::INDEX_QUERY_CEILING, $queries, sprintf(
                '[%s] ran %d queries for a page of rows. That is one per row, not one per relation.',
                $screen,
                $queries,
            ));
        }
    }

    #[Test]
    public function the_dashboard_asks_the_schema_about_a_table_once_rather_than_repeatedly(): void
    {
        // Measured before `SchemaPresence`: the dashboard issued eleven
        // metadata lookups asking the same three questions over and over,
        // because each count checks that its table exists first. Cheap
        // queries, but the dashboard is the landing page and they were pure
        // repetition.
        $this->seedCatalogue(self::SMALL);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user())->get('/')->assertOk();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existenceChecks = 0;
        $tables = [];

        foreach ($log as $entry) {
            $sql = (string) $entry['query'];

            // The shape every engine's "does this table exist" takes: a lookup
            // against the catalogue rather than against the table itself.
            if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'information_schema')) {
                $existenceChecks++;
                $tables[$sql] = true;
            }
        }

        $this->assertSame(
            count($tables),
            $existenceChecks,
            'The dashboard asked the schema the same question twice.',
        );
    }

    // ----------------------------------------------------------- fixtures

    /**
     * Measure with at least this many tracks in the catalogue.
     *
     * The database is **grown** between the two measurements rather than
     * rebuilt: `RefreshDatabase` wraps each test in a transaction, and a second
     * refresh inside it is a transaction inside a transaction. Growing is also
     * the more faithful comparison — the second reading is taken against
     * strictly more rows than the first, which is exactly the claim under test.
     *
     * @return array<string, array{queries: int, bytes: int}>
     */
    private function measureAt(int $tracks): array
    {
        $this->seedCatalogue($tracks - Track::query()->count());

        $user = $this->user();
        $measured = [];

        foreach (self::SCREENS as $screen) {
            // A cold container per screen, so each reading is what a real
            // request costs. `SchemaPresence` is bound `scoped` and would
            // otherwise stay warm from the previous measurement — which would
            // make the *second*, larger reading look cheaper than the first
            // and hide the very thing this test exists to catch.
            $this->app->forgetScopedInstances();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $response = $this->actingAs($user)->get($screen);

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $response->assertOk();

            $measured[$screen] = [
                'queries' => $queries,
                'bytes' => strlen((string) json_encode($response->viewData('page')['props'])),
            ];
        }

        return $measured;
    }

    private function seedCatalogue(int $tracks): void
    {
        if ($tracks < 1) {
            return;
        }

        // The same ten artists both times. Creating more on the second pass
        // would make the artist list legitimately longer and the payload
        // comparison meaningless — the growth under test is the *catalogue's*,
        // and only one thing may differ between the two readings.
        $artists = Artist::query()->count() > 0
            ? Artist::query()->orderBy('id')->take(10)->get()
            : Artist::factory()->count(10)->create();

        // Every track credited, because an uncredited catalogue is the one
        // shape in which a credits N+1 cannot show itself.
        foreach (Track::factory()->count($tracks)->create() as $index => $track) {
            $track->artists()->attach($artists[$index % 10]->id, [
                'role' => TrackArtistRole::Primary->value,
                'position' => 0,
            ]);
        }
    }

    private function user(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
