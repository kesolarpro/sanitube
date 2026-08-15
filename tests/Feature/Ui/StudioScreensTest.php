<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\GenerationDetailQuery;
use SaniTube\Ui\Queries\StudioOverviewQuery;
use Tests\TestCase;

/**
 * The studio screens.
 *
 * Two claims carry this ticket. **A provider that is not configured says so**,
 * because most installs have none and a studio that looks ready and fails on
 * use is worse than one that is honest. And **no provider credential reaches
 * the browser** — not the job id, not the audio reference, which is routinely
 * a time-limited signed URL and therefore a bearer credential in a column.
 */
final class StudioScreensTest extends TestCase
{
    use RefreshDatabase;

    private ?User $viewer = null;

    // ------------------------------------------------------------- access

    #[Test]
    public function every_studio_screen_is_behind_authentication(): void
    {
        $project = $this->project();
        $generation = $this->generation();

        $this->get('/studio')->assertRedirect(route('login'));
        $this->get('/studio/projects')->assertRedirect(route('login'));
        $this->get('/studio/projects/'.$project->uuid)->assertRedirect(route('login'));
        $this->get('/studio/generations')->assertRedirect(route('login'));
        $this->get('/studio/generations/'.$generation->uuid)->assertRedirect(route('login'));
    }

    #[Test]
    public function screens_are_addressed_by_uuid_and_never_by_internal_id(): void
    {
        $project = $this->project();
        $generation = $this->generation();
        $user = $this->user();

        $this->actingAs($user)->get('/studio/projects/'.$project->uuid)->assertOk();
        $this->actingAs($user)->get('/studio/generations/'.$generation->uuid)->assertOk();

        $this->actingAs($user)->get('/studio/projects/'.$project->getKey())->assertNotFound();
        $this->actingAs($user)->get('/studio/generations/'.$generation->getKey())->assertNotFound();
    }

    // ------------------------------------------------------ provider state

    #[Test]
    public function a_studio_with_no_provider_says_it_is_not_configured(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->forgetManager();

        $provider = $this->app->make(StudioOverviewQuery::class)->get()['provider'];

        // Not an error. Generation is optional and SaniTube runs fully
        // without it — the three states have to stay distinguishable.
        $this->assertFalse($provider['configured']);
        $this->assertFalse($provider['available']);
        $this->assertNull($provider['name']);
    }

    #[Test]
    public function a_configured_provider_that_cannot_run_is_not_reported_as_ready(): void
    {
        config(['generation.default' => 'nowhere', 'generation.providers' => []]);
        $this->forgetManager();

        $provider = $this->app->make(StudioOverviewQuery::class)->get()['provider'];

        // Named in configuration and unresolvable. An operator who typed the
        // provider name wrong needs to see that, not an empty studio.
        $this->assertTrue($provider['configured']);
        $this->assertFalse($provider['available']);
        $this->assertSame('nowhere', $provider['name']);
    }

    #[Test]
    public function an_available_provider_is_reported_as_available(): void
    {
        $this->useFakeProvider();

        $provider = $this->app->make(StudioOverviewQuery::class)->get()['provider'];

        $this->assertTrue($provider['configured']);
        $this->assertTrue($provider['available']);
    }

    #[Test]
    public function loading_the_studio_never_reaches_a_provider_over_the_network(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->forgetManager();

        // The rule UI-002's review settled: a page load that probed a vendor
        // would make the screen as slow and as unreliable as the slowest
        // supplier. A disabled provider renders a page rather than an error.
        $this->actingAs($this->user())->get('/studio')->assertOk();
    }

    // --------------------------------------------------------------- counts

    #[Test]
    public function the_overview_counts_every_state_including_the_empty_ones(): void
    {
        $this->useFakeProvider();

        $this->generation(MusicGenerationStatus::Completed);
        $this->generation(MusicGenerationStatus::Completed);
        $this->generation(MusicGenerationStatus::Failed);

        $counts = $this->app->make(StudioOverviewQuery::class)->get()['generations'];

        $this->assertSame(3, $counts['total']);
        $this->assertSame(2, $counts[MusicGenerationStatus::Completed->value]);
        $this->assertSame(1, $counts[MusicGenerationStatus::Failed->value]);

        // A state with no rows is a real 0, never an absent key.
        $this->assertSame(0, $counts[MusicGenerationStatus::Draft->value]);
        $this->assertSame(0, $counts[MusicGenerationStatus::Cancelled->value]);
    }

    #[Test]
    public function unanswered_commercial_rights_are_counted_on_the_front_page(): void
    {
        $this->useFakeProvider();

        $this->generation(MusicGenerationStatus::Completed);
        $this->generation(MusicGenerationStatus::Completed);

        $rights = $this->app->make(StudioOverviewQuery::class)->get()['rights'];

        // Both generations completed and both are UNKNOWN: nothing in
        // SaniTube infers a rights answer from audio having arrived, and a
        // count of recordings nobody may sell belongs where it is seen.
        $this->assertSame(2, $rights[CommercialRightsStatus::Unknown->value]);
        $this->assertSame(0, $rights[CommercialRightsStatus::Allowed->value]);
    }

    // -------------------------------------------------------------- leakage

    #[Test]
    public function no_provider_job_id_or_audio_reference_reaches_the_browser(): void
    {
        $this->useFakeProvider();

        $generation = $this->generation(MusicGenerationStatus::Completed);
        $generation->forceFill(['provider_job_id' => 'job_abcdef123456'])->save();

        MusicGenerationResult::query()->create([
            'generation_id' => $generation->getKey(),
            'provider_result_id' => 'res_987654',
            'source_reference' => 'https://cdn.example.test/audio.mp3?token=SECRET-BEARER',
            'title' => 'A result',
            'selected' => false,
            'raw' => ['download_url' => 'https://cdn.example.test/audio.mp3?token=SECRET-BEARER'],
        ]);

        $body = $this->actingAs($this->user())
            ->get('/studio/generations/'.$generation->uuid)
            ->assertOk()
            ->content();

        // A provider's download reference is routinely a time-limited signed
        // URL. Republishing it hands out access SaniTube never checked.
        $this->assertStringNotContainsString('SECRET-BEARER', $body);
        $this->assertStringNotContainsString('cdn.example.test', $body);
        $this->assertStringNotContainsString('job_abcdef123456', $body);
        $this->assertStringNotContainsString('res_987654', $body);
    }

    #[Test]
    public function a_result_reports_whether_there_is_audio_without_saying_where(): void
    {
        $this->useFakeProvider();

        $generation = $this->generation(MusicGenerationStatus::Completed);

        MusicGenerationResult::query()->create([
            'generation_id' => $generation->getKey(),
            'provider_result_id' => 'with-audio',
            'source_reference' => 'https://cdn.example.test/audio.mp3',
            'selected' => true,
        ]);

        MusicGenerationResult::query()->create([
            'generation_id' => $generation->getKey(),
            'provider_result_id' => 'without-audio',
            'source_reference' => null,
            'selected' => false,
        ]);

        $payload = $this->app->make(GenerationDetailQuery::class)->forGeneration($generation->refresh());

        $flags = array_column($payload['results'], 'has_audio');
        sort($flags);

        // A completed generation with no audio is a provider fault, not an
        // empty recording, and that distinction has to reach the screen.
        $this->assertSame([false, true], $flags);

        foreach ($payload['results'] as $result) {
            $this->assertArrayNotHasKey('source_reference', $result);
            $this->assertArrayNotHasKey('raw', $result);
        }

        $this->assertArrayNotHasKey('provider_job_id', $payload);
    }

    // ------------------------------------------------------------- filters

    #[Test]
    public function a_refused_filter_says_so_rather_than_showing_an_unfiltered_list(): void
    {
        $this->useFakeProvider();

        $this->actingAs($this->user())
            ->getJson('/studio/generations?status=NOT_A_STATUS')
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->getJson('/studio/generations?commercial_rights_status=MAYBE')
            ->assertStatus(422);
    }

    #[Test]
    public function a_cursor_from_another_list_is_refused_rather_than_crashing(): void
    {
        $foreign = base64_encode(json_encode(['title' => 'x', 'uuid' => 'y', '_pointsToNextItems' => true]) ?: '');

        $this->actingAs($this->user())
            ->getJson('/studio/generations?cursor='.urlencode($foreign))
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->getJson('/studio/projects?cursor='.urlencode($foreign))
            ->assertStatus(422);
    }

    #[Test]
    public function a_project_detail_shows_only_its_own_generations(): void
    {
        $this->useFakeProvider();

        $mine = $this->project();
        $theirs = $this->project('Another campaign');

        $this->generation(project: $mine);
        $this->generation(project: $mine);
        $this->generation(project: $theirs);

        $response = $this->actingAs($this->user())
            ->get('/studio/projects/'.$mine->uuid)
            ->assertOk();

        $this->assertStringContainsString('&quot;per_page&quot;', $response->content());

        // Two, not three. A project detail that counted the whole studio would
        // make "how many did that campaign produce" unanswerable.
        $props = $this->inertiaProps($response->content());
        $this->assertCount(2, $props['page']['rows']);
    }

    #[Test]
    public function a_project_with_no_target_reports_no_target_rather_than_zero(): void
    {
        $project = $this->project();
        $this->assertNull($project->target_track_count);

        $response = $this->actingAs($this->user())->get('/studio/projects/'.$project->uuid)->assertOk();
        $props = $this->inertiaProps($response->content());

        // Null, not 0. A project with no target has none; 0 would say somebody
        // set a target of nothing.
        $this->assertNull($props['project']['target_track_count']);
    }

    // ---------------------------------------------------------- navigation

    #[Test]
    public function the_studio_is_reachable_from_the_navigation(): void
    {
        $navigation = $this->app->make(NavigationTree::class)->for($this->user());

        $studio = null;

        foreach ($navigation as $section) {
            if ($section['key'] === 'studio') {
                $studio = $section;
            }
        }

        $this->assertIsArray($studio);
        $this->assertSame('/studio', $studio['href']);
        $this->assertTrue($studio['available']);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps(string $html): array
    {
        $matched = preg_match('/data-page="([^"]+)"/', $html, $matches);

        $this->assertSame(1, $matched, 'The response carries no Inertia page object.');

        /** @var array<string, mixed> $page */
        $page = json_decode(html_entity_decode($matches[1]), true);

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }

    private function useFakeProvider(): void
    {
        config([
            'generation.default' => 'fake',
            'generation.providers' => ['fake' => ['driver' => 'fake']],
        ]);

        $this->forgetManager();
        $this->app->make(MusicGenerationManager::class)->register('fake', new FakeMusicGenerationProvider);
    }

    /**
     * The manager reads configuration once, at construction, so a test that
     * changes configuration has to let the container build a new one.
     */
    private function forgetManager(): void
    {
        $this->app->forgetInstance(MusicGenerationManager::class);
    }

    private function project(string $name = 'Autumn instrumentals'): GenerationProject
    {
        return GenerationProject::query()->create([
            'name' => $name,
            'status' => GenerationProjectStatus::Active,
        ]);
    }

    private function generation(
        MusicGenerationStatus $status = MusicGenerationStatus::Queued,
        ?GenerationProject $project = null,
    ): MusicGeneration {
        static $n = 0;
        $n++;

        return MusicGeneration::query()->create([
            'project_id' => $project?->getKey(),
            'provider' => 'fake',
            'status' => $status,
            'prompt' => 'An ambient piece, number '.$n,
            'language_code' => 'zxx',
            'instrumental' => true,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);
    }

    private function user(UserRole $role = UserRole::Owner): User
    {
        if ($this->viewer instanceof User) {
            return $this->viewer;
        }

        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $this->viewer = $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
