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
use SaniTube\Catalog\Enums\CompositionStatus;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\CompositionContributor;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\CompositionDetailQuery;
use SaniTube\Ui\Queries\CompositionIndexQuery;
use Tests\TestCase;

/**
 * Compositions — the works, as distinct from the recordings of them.
 *
 * The assertion that matters most in this file is the one about vocabulary:
 * `composition_contributor.share` is a catalogue credit, and nothing on this
 * screen may present it as an ownership split or a royalty entitlement. The
 * rights domain does not exist yet, and a screen that implies one has been
 * decided is a screen that invents a financial fact.
 */
final class CatalogCompositionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_list_is_behind_authentication(): void
    {
        $this->get('/catalog/compositions')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_compositions_and_is_reachable_only_by_uuid(): void
    {
        $composition = Composition::factory()->create();
        $user = $this->user();

        $this->actingAs($user)
            ->get('/catalog/compositions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Compositions/Index'));

        $this->actingAs($user)->get('/catalog/compositions/'.$composition->uuid)->assertOk();
        $this->actingAs($user)->get('/catalog/compositions/'.$composition->getKey())->assertNotFound();
    }

    #[Test]
    public function listing_more_compositions_does_not_cost_more_queries(): void
    {
        Composition::factory()->count(2)->create();
        $small = $this->queriesForIndex();

        Composition::factory()->count(10)->create();
        $larger = $this->queriesForIndex();

        $this->assertSame($small, $larger, sprintf('Cost grew from %d to %d queries.', $small, $larger));
    }

    #[Test]
    public function a_writer_share_is_a_credit_and_never_named_as_ownership(): void
    {
        $composition = Composition::factory()->create();
        $this->credit($composition, 60.0);

        $detail = app(CompositionDetailQuery::class)->forComposition($composition);
        $credit = $detail['contributors'][0];

        $this->assertSame(60.0, $credit['credited_share']);
        $this->assertArrayNotHasKey('share', $credit, 'A bare `share` key invites reading it as ownership.');

        // And no ownership vocabulary anywhere in the payload.
        $encoded = (string) json_encode($detail);

        foreach (['ownership', 'royalt', 'entitlement'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }

    #[Test]
    public function a_revoked_iswc_is_not_presented_as_current_identity(): void
    {
        $composition = Composition::factory()->create();
        $this->iswc($composition, 'T-000.000.001-0', active: false);
        $this->iswc($composition, 'T-000.000.002-0');

        $detail = app(CompositionDetailQuery::class)->forComposition($composition);

        $this->assertSame(['T-000.000.002-0'], array_column($detail['identifiers'], 'value'));
        $this->assertSame('T-000.000.002-0', app(CompositionIndexQuery::class)->paginate()['rows'][0]['iswc']);
        $this->assertSame(1, ExternalIdentifier::query()->revoked()->count(), 'Audit code lost the revoked row.');
    }

    #[Test]
    public function only_a_revoked_iswc_means_the_list_reports_none(): void
    {
        $composition = Composition::factory()->create();
        $this->iswc($composition, 'T-000.000.001-0', active: false);

        $this->assertNull(app(CompositionIndexQuery::class)->paginate()['rows'][0]['iswc']);
    }

    #[Test]
    public function the_detail_payload_carries_no_internal_key(): void
    {
        $composition = Composition::factory()->create();

        $encoded = (string) json_encode(app(CompositionDetailQuery::class)->forComposition($composition));

        foreach (['"id"', '"composition_id"', '"contributor_id"', '"track_id"'] as $key) {
            $this->assertStringNotContainsString($key, $encoded, sprintf('%s reached the browser.', $key));
        }
    }

    #[Test]
    public function shown_counts_report_what_was_returned(): void
    {
        foreach ([0, 1, 3] as $credited) {
            $composition = Composition::factory()->create();

            for ($index = 0; $index < $credited; $index++) {
                $this->credit($composition, null, $index);
            }

            $detail = app(CompositionDetailQuery::class)->forComposition($composition);

            $this->assertSame($credited, $detail['contributors_shown'], sprintf('with %d writers', $credited));
            $this->assertFalse($detail['contributors_truncated']);
        }
    }

    #[Test]
    public function an_invalid_status_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/compositions?status=NOT_A_STATUS')->assertStatus(422);
    }

    #[Test]
    public function an_invalid_boolean_filter_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/compositions?public_domain=maybe')->assertStatus(422);
    }

    #[Test]
    public function a_malformed_cursor_is_refused_rather_than_crashing(): void
    {
        $user = $this->user();

        foreach (['not-base64!!', base64_encode('{"id":5}')] as $cursor) {
            $this->actingAs($user)->get('/catalog/compositions?cursor='.urlencode($cursor))->assertStatus(422);
        }
    }

    #[Test]
    public function an_unrecognised_filter_is_refused_at_the_read_model_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CompositionIndexQuery::class)->paginate(['status' => "DRAFT' OR 1=1 --"]);
    }

    #[Test]
    public function valid_filters_narrow_the_list_and_are_echoed_canonically(): void
    {
        Composition::factory()->count(2)->create(['status' => CompositionStatus::Draft]);
        Composition::factory()->count(3)->create(['status' => CompositionStatus::Registered]);

        $this->actingAs($this->user())
            ->get('/catalog/compositions?status='.CompositionStatus::Draft->value)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertCount(2, $props['page']['rows']);
                $this->assertSame(CompositionStatus::Draft->value, $props['filters']['status']);
                $this->assertNull($props['filters']['language']);
            });
    }

    #[Test]
    public function the_cursor_carries_no_internal_key_and_pages_do_not_repeat(): void
    {
        Composition::factory()->count(5)->create();

        $seen = [];
        $cursor = null;

        do {
            $page = app(CompositionIndexQuery::class)->paginate([], $cursor, 2);

            if ($cursor === null) {
                $decoded = (string) base64_decode((string) $page['next_cursor'], true);
                $this->assertStringNotContainsString('"id"', $decoded);
                $this->assertStringContainsString('title', $decoded);
            }

            foreach (array_column($page['rows'], 'uuid') as $uuid) {
                $this->assertNotContains($uuid, $seen, 'A composition appeared on two pages.');
                $seen[] = $uuid;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
    }

    // --------------------------------------------------------------- helpers

    private function credit(Composition $composition, ?float $share, int $position = 0): void
    {
        CompositionContributor::query()->create([
            'composition_id' => $composition->getKey(),
            'contributor_id' => Contributor::factory()->create()->getKey(),
            'role' => CompositionContributorRole::Composer,
            'share' => $share,
            'position' => $position,
        ]);
    }

    private function iswc(Composition $composition, string $value, bool $active = true): void
    {
        ExternalIdentifier::query()->create([
            'identifiable_type' => $composition->getMorphClass(),
            'identifiable_id' => $composition->getKey(),
            'type' => ExternalIdentifierType::Iswc,
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

        app(CompositionIndexQuery::class)->paginate();

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
