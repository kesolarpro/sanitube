<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\CompositionContributorRole;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\CompositionContributor;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Contributors\Enums\ContributorType;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\ContributorDetailQuery;
use SaniTube\Ui\Queries\ContributorIndexQuery;
use Tests\TestCase;

/**
 * Contributors, holding the guarantees the track and artist screens
 * established: active identifiers only, filters refused rather than ignored,
 * a cursor carrying no internal key, counts that report what was returned, and
 * a capped list with a deterministic order.
 */
final class CatalogContributorsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_list_is_behind_authentication(): void
    {
        $this->get('/catalog/contributors')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_contributors_and_is_reachable_only_by_uuid(): void
    {
        $contributor = Contributor::factory()->create();
        $user = $this->user();

        $this->actingAs($user)
            ->get('/catalog/contributors')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Contributors/Index'));

        $this->actingAs($user)->get('/catalog/contributors/'.$contributor->uuid)->assertOk();
        $this->actingAs($user)->get('/catalog/contributors/'.$contributor->getKey())->assertNotFound();
    }

    #[Test]
    public function listing_more_contributors_does_not_cost_more_queries(): void
    {
        Contributor::factory()->count(2)->create();
        $small = $this->queriesForIndex();

        Contributor::factory()->count(10)->create();
        $larger = $this->queriesForIndex();

        $this->assertSame($small, $larger, sprintf('Cost grew from %d to %d queries.', $small, $larger));
    }

    #[Test]
    public function the_detail_payload_carries_no_internal_key(): void
    {
        $contributor = Contributor::factory()->create();

        $encoded = (string) json_encode(app(ContributorDetailQuery::class)->forContributor($contributor));

        foreach (['"id"', '"contributor_id"', '"composition_id"', '"track_id"'] as $key) {
            $this->assertStringNotContainsString($key, $encoded, sprintf('%s reached the browser.', $key));
        }
    }

    #[Test]
    public function the_legal_name_is_carried_separately_rather_than_merged_away(): void
    {
        // Legal name and display name are different facts. Merging them into
        // one field is how a legal name gets published by somebody who thought
        // they were showing a stage name.
        $contributor = Contributor::factory()->create([
            'legal_name' => 'Jane Aurelia Smith',
            'display_name' => 'J. Smith',
        ]);

        $detail = app(ContributorDetailQuery::class)->forContributor($contributor);

        $this->assertSame('J. Smith', $detail['name']);
        $this->assertSame('Jane Aurelia Smith', $detail['legal_name']);
        $this->assertSame('J. Smith', $detail['display_name']);
    }

    #[Test]
    public function a_revoked_identifier_is_not_presented_as_current_identity(): void
    {
        $contributor = Contributor::factory()->create();
        $this->identifier($contributor, 'IPI-OLD', active: false);
        $this->identifier($contributor, 'IPI-CURRENT');

        $values = array_column(app(ContributorDetailQuery::class)->forContributor($contributor)['identifiers'], 'value');

        $this->assertSame(['IPI-CURRENT'], $values);
        $this->assertSame(1, ExternalIdentifier::query()->revoked()->count(), 'Audit code lost sight of the revoked row.');
    }

    #[Test]
    public function a_composition_credit_share_is_labelled_as_a_credit_not_ownership(): void
    {
        // The distinction the rights domain will depend on. A credit share is
        // catalogue evidence of who wrote what; it is not a royalty
        // entitlement, and nothing here may be reused as one.
        $contributor = Contributor::factory()->create();
        $composition = Composition::factory()->create();

        CompositionContributor::query()->create([
            'composition_id' => $composition->getKey(),
            'contributor_id' => $contributor->getKey(),
            'role' => CompositionContributorRole::Composer,
            'share' => 50.0,
            'position' => 1,
        ]);

        $detail = app(ContributorDetailQuery::class)->forContributor($contributor);
        $credit = $detail['compositions'][0];

        $this->assertArrayHasKey('credited_share', $credit);
        $this->assertArrayNotHasKey('share', $credit, 'A bare `share` key invites reading it as ownership.');
        $this->assertArrayNotHasKey('ownership', $credit);
        $this->assertSame(50.0, $credit['credited_share']);
    }

    #[Test]
    public function shown_counts_report_what_was_returned(): void
    {
        foreach ([0, 1, 3] as $credited) {
            $contributor = $this->contributorWithCompositions($credited);
            $detail = app(ContributorDetailQuery::class)->forContributor($contributor);

            $this->assertSame($credited, $detail['compositions_shown'], sprintf('with %d credits', $credited));
            $this->assertCount($credited, $detail['compositions']);
            $this->assertFalse($detail['compositions_truncated']);
        }
    }

    #[Test]
    public function the_capped_credit_list_is_ordered_and_stable(): void
    {
        $contributor = $this->contributorWithCompositions(6);
        $query = app(ContributorDetailQuery::class);

        $first = array_column($query->forContributor($contributor)['compositions'], 'title');
        $second = array_column($query->forContributor($contributor->fresh())['compositions'], 'title');

        $this->assertSame($first, $second, 'The credit list changed between two identical loads.');

        $sorted = $first;
        sort($sorted);
        $this->assertSame($sorted, $first, 'The credit list is not ordered by title.');
    }

    // ------------------------------------------------- invalid filters are 422

    #[Test]
    public function an_invalid_type_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/contributors?type=ROBOT')->assertStatus(422);
    }

    #[Test]
    public function an_invalid_country_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/contributors?country=FRANCE')->assertStatus(422);
    }

    #[Test]
    public function a_malformed_cursor_is_refused_rather_than_crashing(): void
    {
        $user = $this->user();

        foreach (['not-base64!!', base64_encode('{"id":5}')] as $cursor) {
            $this->actingAs($user)->get('/catalog/contributors?cursor='.urlencode($cursor))->assertStatus(422);
        }
    }

    #[Test]
    public function an_unrecognised_filter_is_refused_at_the_read_model_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ContributorIndexQuery::class)->paginate(['type' => "PERSON' OR 1=1 --"]);
    }

    #[Test]
    public function valid_filters_narrow_the_list_and_are_echoed_canonically(): void
    {
        Contributor::factory()->count(2)->create(['type' => ContributorType::Person]);
        Contributor::factory()->count(3)->create(['type' => ContributorType::Organisation]);

        $this->actingAs($this->user())
            ->get('/catalog/contributors?type='.ContributorType::Person->value)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertCount(2, $props['page']['rows']);
                $this->assertSame(ContributorType::Person->value, $props['filters']['type']);
                $this->assertNull($props['filters']['country']);
            });
    }

    #[Test]
    public function the_cursor_carries_no_internal_key_and_pages_do_not_repeat(): void
    {
        Contributor::factory()->count(5)->create();

        $seen = [];
        $cursor = null;

        do {
            $page = app(ContributorIndexQuery::class)->paginate([], $cursor, 2);

            if ($cursor === null) {
                $decoded = (string) base64_decode((string) $page['next_cursor'], true);
                $this->assertStringNotContainsString('"id"', $decoded);
                $this->assertStringContainsString('legal_name', $decoded);
            }

            foreach (array_column($page['rows'], 'uuid') as $uuid) {
                $this->assertNotContains($uuid, $seen, 'A contributor appeared on two pages.');
                $seen[] = $uuid;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
    }

    // --------------------------------------------------------------- helpers

    private function contributorWithCompositions(int $count): Contributor
    {
        $contributor = Contributor::factory()->create();

        for ($index = 0; $index < $count; $index++) {
            // Titles uncorrelated with insertion order, so a key-based ordering
            // cannot produce sorted output by coincidence and make the ordering
            // assertion pass against a broken implementation.
            $composition = Composition::factory()->create([
                'title' => sprintf('Work %03d', ($index * 37) % 997),
            ]);

            CompositionContributor::query()->create([
                'composition_id' => $composition->getKey(),
                'contributor_id' => $contributor->getKey(),
                'role' => CompositionContributorRole::Composer,
                'position' => 1,
            ]);
        }

        return $contributor;
    }

    private function identifier(Contributor $contributor, string $value, bool $active = true): void
    {
        ExternalIdentifier::query()->create([
            'identifiable_type' => $contributor->getMorphClass(),
            'identifiable_id' => $contributor->getKey(),
            'type' => ExternalIdentifierType::Ipi,
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

        app(ContributorIndexQuery::class)->paginate();

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
