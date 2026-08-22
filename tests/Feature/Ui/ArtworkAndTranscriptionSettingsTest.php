<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use Tests\TestCase;

/**
 * The two suppliers an installation could pay and could not configure.
 *
 * CFG-006. Artwork generation reads eighteen environment variables and a
 * credential; transcription reads eight and a credential of its own. None of
 * the twenty-eight appeared on any screen, so an operator who wanted covers
 * generated, or transcripts made, had exactly one way to ask for either: a
 * shell on the server, and a `.env` file they then had to remember to reflect
 * into the config cache.
 *
 * Two of those settings are the reason this could not wait.
 *
 *   - **`SANITUBE_TRANSCRIPTION_AUTOMATIC`** turns a paid, per-file call on
 *     for every master the installation already holds. Off by default and
 *     deliberately so; switching it on over four thousand files spends four
 *     thousand calls before anybody reads the first transcript, and OPS-002's
 *     pause guards the queue's depth rather than an external bill.
 *   - **The artwork requirement thresholds**, which disagree with the one size
 *     the shipped provider is asked for. That disagreement is intentional —
 *     generation refuses out of the box rather than producing covers this
 *     platform's own validator rejects — and resolving it is the first thing
 *     anybody configuring artwork does. It required SSH.
 *
 * The credentials are USR-001's rule and not a new one: an ADMIN reads whether
 * a key is configured and does not replace it.
 */
final class ArtworkAndTranscriptionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-cfg006-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'SANITUBE_ARTWORK_PROVIDER=openai',
            'SANITUBE_ARTWORK_OPENAI_KEY=the-existing-artwork-key',
            'SANITUBE_ARTWORK_MINIMUM_PIXELS=3000',
            'SANITUBE_ARTWORK_DAILY_LIMIT=0',
            'SANITUBE_TRANSCRIPTION_PROVIDER=openai',
            'SANITUBE_TRANSCRIPTION_OPENAI_KEY=the-existing-transcription-key',
            'SANITUBE_TRANSCRIPTION_AUTOMATIC=false',
        ])."\n");

        config([
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.key' => 'the-existing-artwork-key',
            'transcription.provider' => 'openai',
            'transcription.providers.openai.key' => 'the-existing-transcription-key',
        ]);

        $this->app->bind(EnvironmentFile::class, fn (): EnvironmentFile => new EnvironmentFile($this->sandbox.'/.env'));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->sandbox.'/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->sandbox);

        parent::tearDown();
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function both_sections_are_on_the_settings_screen(): void
    {
        $keys = array_map(
            static fn (array $section): string => (string) $section['key'],
            $this->sections(),
        );

        $this->assertContains('artwork', $keys);
        $this->assertContains('transcription', $keys);
    }

    #[Test]
    public function the_shipped_default_is_a_choice_the_screen_offers(): void
    {
        // Both families default to `none`, and a screen that offered only the
        // real providers would make the shipped configuration the one option
        // an operator could not pick — or return to.
        config(['artwork.default_provider' => 'none', 'transcription.provider' => 'none']);

        foreach (['artwork', 'transcription'] as $key) {
            $section = $this->section($key);

            $this->assertContains('none', $section['options'], sprintf('[%s] does not offer `none`.', $key));
            $this->assertTrue($section['known'], sprintf('[%s] reports its own default as unknown.', $key));
        }
    }

    #[Test]
    public function only_the_selected_providers_variables_are_offered(): void
    {
        config(['artwork.default_provider' => 'none', 'transcription.provider' => 'none']);

        $published = $this->variablesOf('artwork') + $this->variablesOf('transcription');

        // A field nothing reads is worse than no field: it is one an operator
        // sets, saves, and then waits for.
        $this->assertArrayNotHasKey('SANITUBE_ARTWORK_OPENAI_MODEL', $published);
        $this->assertArrayNotHasKey('SANITUBE_TRANSCRIPTION_OPENAI_MODEL', $published);

        // The choice itself always is, because it is how somebody gets back.
        $this->assertArrayHasKey('SANITUBE_ARTWORK_PROVIDER', $published);
        $this->assertArrayHasKey('SANITUBE_TRANSCRIPTION_PROVIDER', $published);
    }

    #[Test]
    public function neither_section_publishes_a_credential(): void
    {
        foreach (['artwork', 'transcription'] as $key) {
            $section = $this->section($key);

            $this->assertNotSame([], $section['secrets'], sprintf('[%s] reports no credential at all.', $key));

            foreach ($section['secrets'] as $secret) {
                $this->assertTrue($secret['configured']);
                $this->assertArrayNotHasKey('value', $secret);
            }

            // No value, no mask, no length: a length narrows a search and a
            // suffix confirms a guess.
            $this->assertStringNotContainsString('the-existing-artwork-key', (string) json_encode($section));
            $this->assertStringNotContainsString('the-existing-transcription-key', (string) json_encode($section));
        }
    }

    // ------------------------------------------------------------- the write

    #[Test]
    public function the_decision_that_spends_money_can_be_made_and_unmade_from_the_screen(): void
    {
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_TRANSCRIPTION_AUTOMATIC' => 'true'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_AUTOMATIC=true', $this->environment());

        // The half that matters more. Somebody who has just watched a backlog
        // start spending needs the way back, and it is the same field.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_TRANSCRIPTION_AUTOMATIC' => 'false'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_AUTOMATIC=false', $this->environment());
    }

    #[Test]
    public function a_switch_is_two_words_and_never_anything_else(): void
    {
        // `on`, `1` and `yes` all read as false through Laravel's env casting,
        // so accepting one would be a saved setting that silently means the
        // opposite of what was typed.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_TRANSCRIPTION_AUTOMATIC' => 'yes'])
            ->assertSessionHasErrors();

        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_AUTOMATIC=false', $this->environment());
    }

    #[Test]
    public function the_requirement_that_blocks_generation_is_editable(): void
    {
        // The shipped minimum and the shipped provider size genuinely
        // disagree, and this is the field that resolves it.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_ARTWORK_MINIMUM_PIXELS' => '1024'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_ARTWORK_MINIMUM_PIXELS=1024', $this->environment());
    }

    #[Test]
    public function zero_stays_sayable_everywhere_it_means_something(): void
    {
        // Zero is "no requirement" for a threshold and "no ceiling" for a
        // limit, and it is the shipped default for most of them. A `min:1`
        // anywhere here would make the configuration this platform ships
        // unrepresentable on the form that edits it.
        $zeroes = [
            'SANITUBE_ARTWORK_MINIMUM_PIXELS',
            'SANITUBE_ARTWORK_MAXIMUM_PIXELS',
            'SANITUBE_ARTWORK_MAXIMUM_BYTES',
            'SANITUBE_ARTWORK_DAILY_LIMIT',
            'SANITUBE_ARTWORK_WEEKLY_LIMIT',
            'SANITUBE_ARTWORK_MONTHLY_LIMIT',
            'SANITUBE_ARTWORK_CIRCUIT_FAILURES',
            'SANITUBE_TRANSCRIPTION_MAX_BYTES',
        ];

        foreach ($zeroes as $variable) {
            $this->actingAs($this->user())
                ->patch('/settings', [$variable => '0'])
                ->assertSessionHasNoErrors();

            $this->assertStringContainsString($variable.'=0', $this->environment(), $variable.' cannot be set to zero.');
        }
    }

    #[Test]
    public function a_provider_this_build_cannot_resolve_is_refused(): void
    {
        // A free-text provider name is an installation that resolves to
        // nothing on the next request, saved by a screen that was showing the
        // correct list of choices while it accepted it.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_ARTWORK_PROVIDER' => 'midjourney'])
            ->assertSessionHasErrors();

        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_TRANSCRIPTION_PROVIDER' => 'whisper-local'])
            ->assertSessionHasErrors();

        $environment = $this->environment();

        $this->assertStringContainsString('SANITUBE_ARTWORK_PROVIDER=openai', $environment);
        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_PROVIDER=openai', $environment);
    }

    #[Test]
    public function an_output_format_this_platform_would_then_reject_is_refused(): void
    {
        // Unlike the model, this is a closed list, and the difference is real:
        // these three are what the platform's own validator accepts rather
        // than what a vendor might add next week.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_ARTWORK_OPENAI_FORMAT' => 'tiff'])
            ->assertSessionHasErrors();
    }

    // ------------------------------------------------------ who may write it

    #[Test]
    public function an_administrator_may_tune_both_suppliers_and_replace_neither_key(): void
    {
        // USR-001's rule, reaching the two families that had no screen when it
        // was written. Operating the platform includes deciding what artwork
        // may cost; it does not include changing what the platform pays with.
        $administrator = $this->user(UserRole::Admin);

        $this->actingAs($administrator)
            ->patch('/settings', ['SANITUBE_ARTWORK_DAILY_LIMIT' => '25'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_ARTWORK_DAILY_LIMIT=25', $this->environment());

        foreach (['SANITUBE_ARTWORK_OPENAI_KEY', 'SANITUBE_TRANSCRIPTION_OPENAI_KEY'] as $credential) {
            $this->actingAs($administrator)
                ->patch('/settings', [$credential => 'a-key-an-administrator-chose'])
                ->assertSessionHasErrors('settings');
        }

        $environment = $this->environment();

        $this->assertStringContainsString('SANITUBE_ARTWORK_OPENAI_KEY=the-existing-artwork-key', $environment);
        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_OPENAI_KEY=the-existing-transcription-key', $environment);
        $this->assertStringNotContainsString('a-key-an-administrator-chose', $environment);
    }

    #[Test]
    public function an_owner_replaces_either_key(): void
    {
        $this->actingAs($this->user())
            ->patch('/settings', [
                'SANITUBE_ARTWORK_OPENAI_KEY' => 'the-owners-artwork-key',
                'SANITUBE_TRANSCRIPTION_OPENAI_KEY' => 'the-owners-transcription-key',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $environment = $this->environment();

        $this->assertStringContainsString('SANITUBE_ARTWORK_OPENAI_KEY=the-owners-artwork-key', $environment);
        $this->assertStringContainsString('SANITUBE_TRANSCRIPTION_OPENAI_KEY=the-owners-transcription-key', $environment);
    }

    #[Test]
    public function a_member_reads_nothing_and_writes_nothing(): void
    {
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->get('/settings')->assertForbidden();
        $this->actingAs($member)
            ->patch('/settings', ['SANITUBE_ARTWORK_DAILY_LIMIT' => '25'])
            ->assertForbidden();

        $this->assertStringContainsString('SANITUBE_ARTWORK_DAILY_LIMIT=0', $this->environment());
    }

    // ------------------------------------------------------- the .env example

    #[Test]
    public function every_variable_these_two_sections_name_is_documented(): void
    {
        // The screen is where a setting is changed; `.env.example` is where an
        // operator finds out it exists and what a value means. Artwork had
        // eighteen variables and not one line of documentation, so choosing
        // the provider gave somebody a supplier with no documented way to
        // point it anywhere.
        config(['artwork.default_provider' => 'openai', 'transcription.provider' => 'openai']);

        $example = (string) file_get_contents(base_path('.env.example'));
        $published = [...array_keys($this->variablesOf('artwork')), ...array_keys($this->variablesOf('transcription'))];

        $this->assertGreaterThan(20, count($published));

        foreach ($published as $variable) {
            $this->assertStringContainsString(
                $variable.'=',
                $example,
                sprintf('[%s] is on the settings screen and named nowhere in .env.example.', $variable),
            );
        }
    }

    // ---------------------------------------------------------- the fixtures

    /**
     * @return list<array<string, mixed>>
     */
    private function sections(): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->actingAs($this->user())
            ->get('/settings')
            ->viewData('page')['props']['settings']['sections'];

        return $sections;
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        foreach ($this->sections() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail(sprintf('The settings screen has no [%s] section.', $key));
    }

    /**
     * Every variable a section names, settings and credentials alike.
     *
     * @return array<string, true>
     */
    private function variablesOf(string $key): array
    {
        $section = $this->section($key);
        $variables = [];

        /** @var list<array<string, mixed>> $entries */
        $entries = [...$section['settings'], ...$section['secrets']];

        foreach ($entries as $entry) {
            $variables[(string) $entry['variable']] = true;
        }

        return $variables;
    }

    private function environment(): string
    {
        return (string) file_get_contents($this->sandbox.'/.env');
    }

    private function user(UserRole $role = UserRole::Owner): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
