<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Queries\DashboardQuery;
use Tests\TestCase;

/**
 * The dashboard, and the one rule it lives or dies by.
 *
 * A dashboard's whole value is that an operator can trust what it says. The
 * assertions that matter most here are therefore not "does it render" but
 * "does it ever show a number it did not count".
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_is_behind_authentication(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_renders_for_a_signed_in_person(): void
    {
        $this->actingAs($this->user())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/Index'));
    }

    // ------------------------------------------------------------ real counts

    #[Test]
    public function every_catalogue_count_is_a_real_number_on_a_migrated_database(): void
    {
        // The guardrail against a mistyped table name. `count()` returns null
        // for a table that does not exist, which is correct behaviour and also
        // exactly how a typo would hide: the figure would read "unknown"
        // forever and nobody would know the query was wrong.
        $snapshot = $this->snapshot();

        foreach ($snapshot['catalogue'] as $key => $total) {
            $this->assertIsInt($total, sprintf('catalogue.%s could not be counted — is the table named correctly?', $key));
        }

        foreach (['tracks_by_status', 'releases_by_status'] as $group) {
            $this->assertIsArray($snapshot[$group], $group.' could not be counted.');
        }

        $this->assertIsArray($snapshot['ingestion']['candidates_by_status']);
        $this->assertIsArray($snapshot['distribution']['deliveries_by_status']);
        $this->assertIsInt($snapshot['media']['analyses']);
    }

    #[Test]
    public function the_figures_are_counted_from_the_catalogue(): void
    {
        Track::factory()->ready()->count(3)->create();
        Release::factory()->ready(2)->create();

        $snapshot = $this->snapshot();

        // The release factory creates its own tracks, so tracks are asserted
        // against the database rather than against the literal 3.
        $this->assertSame(Track::query()->count(), $snapshot['catalogue']['tracks']);
        $this->assertSame(1, $snapshot['catalogue']['releases']);
        $this->assertSame(
            Track::query()->where('status', TrackStatus::Ready)->count(),
            $snapshot['tracks_by_status'][TrackStatus::Ready->value] ?? null,
        );
    }

    #[Test]
    public function a_lifecycle_state_with_no_rows_is_a_zero_rather_than_a_missing_key(): void
    {
        // Otherwise "no archived tracks" and "the archived bucket fell out of
        // the result set" are indistinguishable to the page rendering it.
        $counts = $this->snapshot()['tracks_by_status'];

        $this->assertIsArray($counts);

        foreach (TrackStatus::cases() as $case) {
            $this->assertArrayHasKey($case->value, $counts);
            $this->assertIsInt($counts[$case->value]);
        }
    }

    // ------------------------------------------------------ unknown ≠ zero

    #[Test]
    public function a_count_that_cannot_be_taken_is_null_and_never_zero(): void
    {
        // The assertion this whole read model exists for. An operator reading
        // "0 failed jobs" concludes the queue is healthy; if the truth is that
        // the table is absent, the dashboard caused that wrong conclusion.
        Schema::drop('failed_jobs');

        $snapshot = $this->snapshot();

        $this->assertNull($snapshot['jobs']['failed']);
        $this->assertNotSame(0, $snapshot['jobs']['failed']);

        // And the counts that *can* still be taken are unaffected.
        $this->assertIsInt($snapshot['jobs']['pending']);
    }

    #[Test]
    public function a_provider_that_cannot_answer_is_reported_as_unknown(): void
    {
        $snapshot = $this->snapshot();

        foreach ([$snapshot['capabilities']['ai'], $snapshot['generation']['provider']] as $provider) {
            $this->assertArrayHasKey('name', $provider);
            $this->assertArrayHasKey('available', $provider);
            $this->assertTrue(
                $provider['available'] === null || is_bool($provider['available']),
                'Availability must be a boolean or an honest unknown.',
            );
        }
    }

    // -------------------------------------------------------------- the cost

    #[Test]
    public function the_snapshot_costs_the_same_whether_the_catalogue_is_small_or_large(): void
    {
        // The N+1 guard. A dashboard that issues a query per track is fine on a
        // demo and unusable at the nine hundred tracks this platform exists to
        // manage — and the difference does not show up until it is in
        // production.
        Track::factory()->ready()->count(2)->create();
        $small = $this->queriesForSnapshot();

        Track::factory()->ready()->count(12)->create();
        $larger = $this->queriesForSnapshot();

        $this->assertSame(
            $small,
            $larger,
            sprintf('Snapshot cost grew from %d to %d queries as rows were added.', $small, $larger),
        );
    }

    // --------------------------------------------------------------- secrets

    #[Test]
    public function no_credential_reaches_the_dashboard(): void
    {
        config([
            'sanitube.api.internal_token' => 'the-internal-api-token-value',
            'ai.providers.openai.key' => 'sk-a-real-looking-openai-key',
        ]);

        $body = $this->actingAs($this->user())->get('/')->assertOk()->content();

        foreach (['the-internal-api-token-value', 'sk-a-real-looking-openai-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, sprintf('[%s] reached the browser.', $secret));
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return app(DashboardQuery::class)->snapshot();
    }

    private function queriesForSnapshot(): int
    {
        $count = 0;

        DB::flushQueryLog();
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->snapshot();

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
