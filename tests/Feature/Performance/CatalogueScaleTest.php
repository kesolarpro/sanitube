<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackContributorRole;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Deduplication\Enums\DuplicateBasis;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Releases\Models\Release;
use SaniTube\Transcription\Models\Transcript;
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
 * Measured at PERF-005: every index screen is 1–5 queries and a payload
 * identical at both sizes, because they all page with a cursor and eager-load
 * their relations. Five is the enrichment queue, which reads three tables the
 * others do not — the asset, its transcript, and the track and analysis behind
 * it — once each for the whole page. The dashboard is about thirty queries —
 * one per aggregate it displays, none of them per-row — and also identical at
 * both sizes.
 *
 * **PERF-002: four of the nine screens were measured against empty tables.**
 * The fixture seeded tracks and artists, and nothing else — so
 * `/catalog/assets`, `/ingestion/candidates`, `/ingestion/batches` and
 * `/duplicates` rendered nothing at sixty tracks and nothing at four hundred.
 * "The same number of queries at both sizes" is true of a screen with no rows,
 * and an N+1 over candidates could never have shown itself.
 *
 * So each screen now declares **where its rows live in the payload**, and a
 * test asserts every one of them is non-empty before anything is measured. A
 * screen listed with nothing in it fails loudly rather than passing quietly.
 *
 * **PERF-005 measured the five PERF-002 could not reach** — enrichment,
 * distribution, production, generations, projects — and closed the hole that
 * list itself was: a screen is only checked here if somebody remembered to
 * write it down, and nothing was reading the application to find out. The
 * classification is now checked against the router, so the answer to "have we
 * covered the screens" is derived rather than asserted.
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
     * The name every provider column in this fixture carries.
     *
     * Deliberately not the name of a real one. These rows record *that* a
     * suggestion, a delivery and a generation exist and what they cost to
     * list; nothing here configures a provider or asks one anything, and a
     * fixture that wrote a real provider's name into four hundred rows would
     * read, to anyone grepping for it, as an installation that had one.
     */
    private const FIXTURE_PROVIDER = 'measured-provider';

    private const FIXTURE_MODEL = 'measured-model';

    private const FIXTURE_VERSION = 'measured-v1';

    /**
     * Every screen measured, and where the rows it lists live in its payload.
     *
     * **`null` means the screen lists nothing**, and each one says why. It is
     * not an escape hatch: a screen with rows and a `null` here would be
     * measured against whatever the fixture happened to contain, which is the
     * defect PERF-002 exists to fix.
     *
     * @var array<string, string|null>
     */
    private const SCREENS = [
        // Aggregates, not rows. Counted, never listed.
        '/' => null,

        '/catalog/tracks' => 'page.rows',
        '/catalog/artists' => 'page.rows',
        '/catalog/assets' => 'page.rows',
        '/catalog/contributors' => 'page.rows',
        '/catalog/compositions' => 'page.rows',
        '/releases' => 'page.rows',
        '/ingestion/candidates' => 'page.rows',
        '/ingestion/batches' => 'page.rows',
        '/duplicates' => 'page.rows',
        '/system/audit' => 'page.rows',

        // PERF-005. These five were named below as out of reach, each needing
        // a chain of domain objects the catalogue fixture did not build. It
        // builds them now, and the reason it is worth the fixture is the
        // comment `SuggestionReviewQuery::rows()` carries: a page query and an
        // analysis query per suggestion lived on that screen precisely because
        // no measurement reached it.
        '/enrichment/suggestions' => 'page.rows',
        '/distribution' => 'page.rows',
        '/production' => 'page.rows',
        '/studio/generations' => 'page.rows',
        '/studio/projects' => 'page.rows',
    ];

    /**
     * Index screens this test does not reach yet, and what each would need.
     *
     * Named rather than left out silently: an uncovered screen that nobody has
     * written down is indistinguishable from one somebody checked.
     *
     * **Empty since PERF-005**, and kept rather than deleted: it is where the
     * guard below sends the next screen that arrives without a fixture, and
     * deleting it would leave that screen with nowhere to go but the exemption
     * list next door — which means something else entirely.
     *
     * @var array<string, string>
     */
    private const NOT_YET_MEASURED = [
    ];

    /**
     * Screens the router offers whose rows do not grow with the catalogue.
     *
     * **Not an exemption list.** Nothing here is skipped because measuring it
     * was inconvenient; each is a screen that would answer the question this
     * file asks with "no, and it could not have". A form lists nothing. A
     * picker answers a search. The queue grows with what is running.
     *
     * The distinction from `NOT_YET_MEASURED` is the whole point of having two
     * lists: that one is work outstanding, this one is work that does not
     * exist. A screen put in the wrong one is a screen nobody comes back to.
     *
     * @var array<string, string>
     */
    private const NOT_A_CATALOGUE_LIST = [
        '/assets/upload' => 'A form. It lists nothing.',
        '/design-system' => 'A gallery of the components themselves, with no catalogue behind it.',
        '/editorial' => 'One row per imprint. It grows with how many labels somebody runs, which is a number a person chose.',
        '/ingestion/import' => 'A form. It lists nothing.',
        '/releases/options/artists' => 'A picker. It answers a search with a bounded number of matches.',
        '/releases/options/artwork' => 'A picker. It answers a search with a bounded number of matches.',
        '/settings' => 'A form. It lists nothing.',
        '/studio' => 'Aggregates, not rows. Counted, never listed.',
        '/system/jobs' => 'The queue. It grows with what is running, which is not what this test varies.',
        '/system/operations' => 'One row per registered background operation. A fixed list, not a table.',
        '/users' => 'One row per person with an account. It grows with the staff, not the catalogue.',
    ];

    /**
     * One request before anything is seeded, and it is load-bearing.
     *
     * `RecordAuditEvent` writes `ip_address` and `user_agent` when an action
     * arrived over HTTP and null when it did not — console work is not given
     * an invented `127.0.0.1`. This test seeds twice, once at each size, and
     * the *second* seeding runs after the first measurement's requests: it
     * looks like HTTP to the recorder and the first one did not. Fifty audit
     * rows then differ by an address and a user-agent apiece, and the payload
     * comparison reads that as the catalogue having grown.
     *
     * It came to about a kilobyte, which was exactly the tolerance, so the
     * test passed or failed on how long a faker happened to make a name. It
     * failed once in CI and passed on the next commit, which is the shape of
     * thing that gets called flaky and re-run. The temptation there is to
     * raise the tolerance, and that would have buried a real measurement under
     * an artefact.
     *
     * Warming the request here makes both seedings identical, and it is also
     * the truer shape: audit rows on a running installation are written during
     * requests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->get('/login');
    }

    /**
     * The guard that makes every other assertion here mean something.
     *
     * PERF-002 found four screens measured against empty tables. "The same
     * number of queries at sixty tracks and at four hundred" is trivially true
     * of a screen that renders nothing at both, and an N+1 over a page of rows
     * cannot appear in a page with no rows.
     *
     * So this runs first, in spirit: every screen that lists something must be
     * listing something. A screen added to the list without the fixture to fill
     * it fails here, by name, rather than joining the four.
     */
    #[Test]
    public function every_measured_screen_actually_has_rows_in_it(): void
    {
        $this->seedCatalogue(self::SMALL);

        $user = $this->user();
        $checked = 0;

        foreach (self::SCREENS as $screen => $path) {
            if ($path === null) {
                continue;
            }

            $response = $this->actingAs($user)->get($screen);
            $response->assertOk();

            /** @var array<string, mixed> $props */
            $props = $response->viewData('page')['props'];

            $rows = data_get($props, $path);

            $this->assertIsArray($rows, sprintf('[%s] has nothing at [%s].', $screen, $path));
            $this->assertNotSame(
                [],
                $rows,
                sprintf(
                    '[%s] was measured with nothing in it, so it agreed with itself about everything.',
                    $screen,
                ),
            );

            $checked++;
        }

        // A loop over nothing passes every assertion it does not make.
        $this->assertGreaterThan(5, $checked);
    }

    /**
     * And every screen the application serves is accounted for, by the router.
     *
     * PERF-002 wrote down the screens it knew it was not reaching, which was
     * the right instinct and the wrong mechanism: a hand-kept list of what is
     * uncovered is correct on the day it is written and silently wrong from
     * the first screen added afterwards. It cannot report a screen nobody
     * remembered to add to it.
     *
     * **So this asks the application what it serves.** Every parameterless
     * `GET` into the interface module is a screen, and every screen must be in
     * exactly one of three places: measured, named as not yet measured with
     * what it would need, or named as something whose rows do not grow with
     * the catalogue. A new screen belongs to none of them and fails here, by
     * name, with the three choices spelled out.
     *
     * It fails in the other direction too. A classified screen that is no
     * longer a route is a sentence about something that does not exist, and
     * those accumulate into a list nobody trusts.
     */
    #[Test]
    public function every_screen_the_router_offers_is_measured_or_accounted_for(): void
    {
        $classified = array_merge(
            array_keys(self::SCREENS),
            array_keys(self::NOT_YET_MEASURED),
            array_keys(self::NOT_A_CATALOGUE_LIST),
        );

        $this->assertSame(
            count($classified),
            count(array_unique($classified)),
            'A screen is classified twice, and the two answers disagree about what it is.',
        );

        $offered = $this->screenRoutes();

        // A loop over nothing passes every assertion it does not make, and a
        // router scan that matched no route would do exactly that.
        $this->assertGreaterThan(20, count($offered));

        foreach ($offered as $uri) {
            $this->assertContains($uri, $classified, sprintf(
                '[%s] is a screen the application serves and nothing here has looked at it. '
                .'Measure it, or say in NOT_YET_MEASURED what it would need, or say in '
                .'NOT_A_CATALOGUE_LIST why its rows do not grow with the catalogue.',
                $uri,
            ));
        }

        foreach ($classified as $uri) {
            $this->assertContains($uri, $offered, sprintf(
                '[%s] is written down here and is not a route. The list has drifted.',
                $uri,
            ));
        }

        foreach (array_merge(self::NOT_YET_MEASURED, self::NOT_A_CATALOGUE_LIST) as $screen => $reason) {
            $this->assertNotSame('', trim($reason), sprintf('[%s] is excluded with no reason.', $screen));
        }
    }

    #[Test]
    public function no_screen_costs_more_queries_because_the_catalogue_is_larger(): void
    {
        $small = $this->measureAt(self::SMALL);
        $large = $this->measureAt(self::LARGE);

        foreach (array_keys(self::SCREENS) as $screen) {
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

        foreach (array_keys(self::SCREENS) as $screen) {
            $growth = $large[$screen]['bytes'] - $small[$screen]['bytes'];

            // A small tolerance rather than equality, and the reason is the
            // dashboard: it displays counts, and `400` is two characters more
            // than `60`. That is a page reporting a bigger catalogue, not a
            // page serialising one.
            //
            // What this measures is growth *with size*, so a screen that is
            // uniformly fat is invisible here — that is what the per-screen
            // ceiling below is for. What it catches is a payload that carries
            // more because the catalogue holds more.
            //
            // It was a kilobyte, and a kilobyte was wide enough to hide two
            // fixture artefacts that together came to almost exactly one: see
            // `setUp()` and the batch in `seedCatalogue()`. With those gone,
            // every screen here grows by between nought and five bytes.
            // Two hundred and fifty-six leaves room for a different engine
            // ordering rows differently, and closes the gap where a leak of a
            // dozen extra rows' worth used to pass.
            $this->assertLessThan(256, $growth, sprintf(
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

        foreach (array_keys(self::SCREENS) as $screen) {
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
     * Every screen the application serves, read from the router.
     *
     * "A screen" is a parameterless `GET` into the interface module. The
     * module filter is what keeps the API, the health endpoint and the
     * framework's own routes out; the parameter filter is what keeps detail
     * pages out, which are a different question — a detail page costs what one
     * record costs and does not grow with the table.
     *
     * @return list<string>
     */
    private function screenRoutes(): array
    {
        $uris = [];

        // The collection is typed as the interface, which is not iterable;
        // asking the concrete collection for its routes says so without
        // widening anything. `RouteAuthorizationTest` reads the router the
        // same way, for the same reason.
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'SaniTube\\Ui\\Http\\Controllers\\')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (str_contains($uri, '{')) {
                continue;
            }

            $uris[] = $uri === '/' ? '/' : '/'.$uri;
        }

        $uris = array_values(array_unique($uris));
        sort($uris);

        return $uris;
    }

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

        foreach (array_keys(self::SCREENS) as $screen) {
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

    /**
     * Grow every table the measured screens read.
     *
     * **PERF-002 rewrote this.** It used to seed tracks and artists only, which
     * left four of the nine measured screens rendering nothing at either size —
     * and a screen with no rows agrees with itself about everything.
     *
     * Each row here exists because a screen lists it. Nothing is seeded for
     * decoration, and nothing that a screen needs is left out: the guard above
     * fails if it is.
     */
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

        // And the same batch both times, for the reason directly above. A
        // second batch is a second *row* on `/ingestion/batches` — a page
        // legitimately reporting a bigger catalogue, except that this is the
        // one screen here that does not fill a page at either size, so the
        // extra row shows up as growth and reads as an unbounded payload.
        // Two hundred and eighty-five bytes of it, consistently.
        $batch = IngestionBatch::query()->first() ?? IngestionBatch::query()->create([
            'source' => IngestionSource::CloudImport,
            'status' => IngestionBatchStatus::Completed,
        ]);

        // And the same operator, whose name is the `actor_label` on fifty
        // audit rows.
        $operator = User::query()->where('name', 'Measured Operator')->first() ?? $this->user();
        $previous = Asset::query()->orderByDesc('id')->first();

        // One editorial profile, for the same reason as the batch: the plan
        // list renders its name, and a second profile is not a bigger
        // catalogue.
        $profile = EditorialProfile::query()->first() ?? EditorialProfile::query()->create([
            'name' => 'Measured Profile',
            'slug' => 'measured-profile',
            'default_language' => 'fr',
            'is_active' => true,
        ]);

        // Every track credited, because an uncredited catalogue is the one
        // shape in which a credits N+1 cannot show itself.
        foreach (Track::factory()->count($tracks)->create() as $index => $track) {
            // Fixed-width names for everything a list orders by. PERF-005
            // pinned the values its own screens sort on and left the faker
            // titles the catalogue screens sort on — and `/catalog/
            // compositions`, ordered by title, drew a different first page at
            // each size whose *byte length* wobbled with the seed: 264 bytes
            // of growth on one CI run against a 256 tolerance, green
            // everywhere else. Same rule everywhere now: only one thing may
            // differ between the two readings, and it is the row count.
            $key = str_pad((string) $track->id, 6, '0', STR_PAD_LEFT);
            $track->update(['title' => 'Measured Track '.$key]);
            $track->artists()->attach($artists[$index % 10]->id, [
                'role' => TrackArtistRole::Primary->value,
                'position' => 0,
            ]);

            // A master per track, so `/catalog/assets` has something to list
            // and the candidate below has bytes to point at.
            $master = Asset::factory()->master()->verified()->create();

            IngestionItem::query()->create([
                'batch_id' => $batch->id,
                'ingestion_key' => hash('sha256', 'scale-'.$track->id),
                'source_reference' => 'library/'.$track->id.'.wav',
                'original_filename' => $track->id.'.wav',
                'status' => IngestionItemStatus::Imported,
            ]);

            TrackCandidate::query()->create([
                'source' => IngestionSource::CloudImport,
                'asset_id' => $master->id,
                'original_filename' => $track->id.'.wav',
                'suggested_title' => $track->title,
                'status' => TrackCandidateStatus::Ready,
            ]);

            // Each master proposed as a duplicate of the one before it, so the
            // review queue grows with the catalogue.
            //
            // Every track, not every other: both readings have to exceed the
            // review page size or the *page* legitimately grows between them,
            // and a fixture that produces thirty rows at sixty tracks and fifty
            // at four hundred reports a bounded screen as unbounded. That is
            // how this line was first written, and the payload test said so.
            if ($previous instanceof Asset) {
                DuplicateRelation::query()->create([
                    'asset_id' => $master->id,
                    'related_asset_id' => $previous->id,
                    'level' => DuplicateLevel::ExactDuplicate,
                    'basis' => DuplicateBasis::Checksum,
                    'decision' => DuplicateDecision::Proposed,
                ]);
            }

            $previous = $master;

            // A contributor per track, credited, because an uncredited
            // catalogue is the one shape in which a credits N+1 cannot show
            // itself — and because `/catalog/contributors` lists them.
            $track->contributors()->attach(
                Contributor::factory()->create(['legal_name' => 'Measured Contributor '.$key])->id,
                ['role' => TrackContributorRole::Producer->value, 'position' => 0],
            );

            Composition::factory()->create(['title' => 'Measured Composition '.$key]);

            // Fixed width, and that is load-bearing rather than tidy.
            //
            // Every list PERF-005 adds orders by `created_at` and breaks the
            // tie on `uuid`. Sixty rows written inside the same second all tie,
            // so the order is the uuids' — random — and the twenty-five rows
            // that land on the first page at four hundred tracks are not the
            // twenty-five that landed there at sixty. Any value whose *length*
            // varies from row to row would then move the payload between the
            // two readings, and the test would be measuring which rows were
            // drawn rather than whether the page is bounded.
            //
            $release = Release::factory()->create(['title' => 'Measured Release '.$key]);

            // What a model said about this master, and the evidence it said it
            // from.
            //
            // The transcript is not decoration. `with('transcript')` loads
            // nothing when `transcript_id` is null, and reading the relation
            // back on a null column returns null without a query — so a
            // suggestion seeded without one cannot tell an eager load from a
            // missing eager load, and removing the eager load would measure
            // the same.
            $transcript = Transcript::query()->create([
                'asset_id' => $master->id,
                'provider' => self::FIXTURE_PROVIDER,
                'provider_version' => self::FIXTURE_VERSION,
                'detected_language' => 'fr',
                'language_hint' => 'fr',
                'text' => 'Measured transcript.',
                'covered_seconds' => 180,
            ]);

            MetadataSuggestion::query()->create([
                'asset_id' => $master->id,
                'task' => MetadataSuggestion::TASK_TRACK_METADATA,
                'provider' => self::FIXTURE_PROVIDER,
                'model' => self::FIXTURE_MODEL,
                'prompt_version' => self::FIXTURE_VERSION,
                'transcript_id' => $transcript->id,

                // Matching the transcript it points at, so `evidenceIsCurrent`
                // answers true rather than the shorter false. The row renders
                // the answer either way; seeding the shape a reviewer actually
                // reads is the one that exercises the comparison.
                'evidence_version' => self::FIXTURE_PROVIDER.':'.self::FIXTURE_VERSION,

                'suggested_title' => 'Suggested Title '.$key,
                'suggested_language' => 'fr',
                'suggested_genres' => ['measured-genre'],
                'suggested_moods' => ['measured-mood'],
                'suggested_themes' => ['measured-theme'],
                'suggested_description' => 'Measured description.',
                'confidence_basis_points' => 8500,
                'rationale' => 'Measured rationale.',
                'status' => SuggestionStatus::Proposed,
            ]);

            // A delivery per release, in the one state that has been handed to
            // nobody. DRAFT is deliberate: a fixture that wrote SUBMITTED rows
            // in bulk would be a fixture asserting four hundred releases had
            // been given to a distributor.
            DistributionDelivery::query()->create([
                'release_id' => $release->id,
                'provider' => self::FIXTURE_PROVIDER,
                'status' => DistributionDeliveryStatus::Draft,
                'idempotency_key' => hash('sha256', 'scale-delivery-'.$track->id),
            ]);

            $plan = ProductionPlan::query()->create([
                'name' => 'Production Plan '.$key,
                'slug' => 'production-plan-'.$key,
                'editorial_profile_id' => $profile->id,
                'autonomy_mode' => AutonomyMode::Manual,
                'status' => ProductionPlanStatus::Active,
                'target_track_count' => 12,
                'cadence_days' => 7,
            ]);

            // Two occasions per plan, in two states, because the counts on that
            // screen are one grouped query over the table that grows fastest
            // here — one row per plan per cadence, for ever — and a plan whose
            // occasions are all in one state cannot show a grouping that has
            // stopped grouping.
            foreach ([
                '2026-01-05' => ProductionSlotStatus::Completed,
                '2026-01-12' => ProductionSlotStatus::Pending,
            ] as $date => $status) {
                ProductionSlot::query()->create([
                    'production_plan_id' => $plan->id,
                    'scheduled_for' => $date,
                    'status' => $status,
                    'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
                ]);
            }

            $project = GenerationProject::query()->create([
                'name' => 'Generation Project '.$key,
                'status' => GenerationProjectStatus::Active,
                'target_track_count' => 12,
                'default_language' => 'fr',
                'default_genre' => 'measured-genre',
            ]);

            // Attached to the project, because the project list counts them and
            // a count of nought is the same number at both sizes whatever the
            // query does.
            MusicGeneration::query()->create([
                'project_id' => $project->id,
                'provider' => self::FIXTURE_PROVIDER,
                'provider_job_id' => 'job-'.$key,
                'status' => MusicGenerationStatus::Draft,
                'prompt' => 'Measured prompt '.$key,
                'language_code' => 'fr',
                'instrumental' => false,
                'commercial_rights_status' => CommercialRightsStatus::Unknown,
            ]);

            // The audit log grows with what an installation does, and it is
            // the one screen where "show me everything" is the whole point.
            //
            // Through the recording service rather than by writing the row:
            // it derives the subject from the action and the actor from the
            // request, and a fixture that guessed those columns would drift
            // from the shape the screen actually reads.
            $this->actingAs($operator);
            $this->audit()->record(AuditAction::IngestionBatchStarted, subjectUuid: $batch->uuid);
        }
    }

    private function audit(): RecordAuditEvent
    {
        return $this->app->make(RecordAuditEvent::class);
    }

    /**
     * A named operator, because the name is measured.
     *
     * `audit_events.actor_label` is a snapshot of whoever acted, it is
     * rendered on `/system/audit`, and a factory name is a random number of
     * characters. Fifty rows of those differ by hundreds of bytes between one
     * seeding and the next — which the payload comparison reads as the
     * catalogue having grown.
     */
    private function user(string $name = 'Measured Operator'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
    }
}
