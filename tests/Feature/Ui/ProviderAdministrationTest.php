<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\SelectableProviders;
use SaniTube\Ui\Settings\WritableSettings;
use Tests\TestCase;

/**
 * Choosing which provider an installation uses.
 *
 * CFG-002. Storage could be switched from the settings screen and nothing else
 * could: generation, AI and distribution published their options as text and
 * were writable nowhere, so the screen listed choices no operator could make.
 * Worse, the one *real* generation engine SaniTube has — self-hosted ACE-Step —
 * had a resolution arm and no declaration, so it was not even among the
 * choices, and setting it by hand produced `UnknownGenerationProvider` and a
 * section reporting the installation's own provider as unknown.
 *
 * The list an operator is offered and the list a save is checked against are
 * the same list, read from the declaration. That is the whole of it: a screen
 * that offers what saving refuses is a screen that is lying, and a save that
 * accepts what the screen never offered is a provider that resolves to nothing
 * on the next request.
 */
final class ProviderAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-providers-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'SANITUBE_GENERATION_PROVIDER=none',
            'SANITUBE_AI_PROVIDER=none',
            'SANITUBE_DISTRIBUTOR=none',
        ])."\n");

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

    // ------------------------------------------------- the engine exists

    #[Test]
    public function the_one_real_generation_engine_can_actually_be_chosen(): void
    {
        // The bug in one line. `acestep` was resolvable and undeclared, so
        // `names()` never mentioned it and the manager threw on it.
        $this->assertContains('acestep', $this->app->make(MusicGenerationManager::class)->names());

        // Resolved by name rather than through `default()`: the manager is
        // built once with the boot-time selection, so what a save changes is
        // the next request's manager, not this one's. What matters here is that
        // the name resolves at all — before the declaration it threw.
        $provider = $this->app->make(MusicGenerationManager::class)->provider('acestep');

        $this->assertSame('acestep', $provider->name());
    }

    #[Test]
    public function the_engine_needs_no_credentials_of_its_own(): void
    {
        config(['generation.default' => 'acestep']);

        // Everything it reaches is the worker's, and the worker is configured
        // in its own section. A generation section asking for an engine
        // address would be asking for the one thing Core must not know: ACE-Step
        // answers with a path on its own filesystem, and the whole point of the
        // worker is that the path never leaves the host that can open it.
        $section = $this->sectionNamed('generation');

        $this->assertSame([], $section['secrets']);
    }

    // -------------------------------------------- the screen and the writer

    #[Test]
    public function every_family_offers_the_same_names_the_writer_accepts(): void
    {
        $writable = $this->app->make(WritableSettings::class);
        $providers = $this->app->make(SelectableProviders::class);

        foreach ([
            'ai' => 'SANITUBE_AI_PROVIDER',
            'generation' => 'SANITUBE_GENERATION_PROVIDER',
            'distribution' => 'SANITUBE_DISTRIBUTOR',
        ] as $family => $variable) {
            $setting = $writable->find($variable);

            $this->assertNotNull($setting, sprintf('[%s] cannot be written at all.', $variable));

            // Derived from the declaration on both sides. A hand-kept rule is
            // a rule that outlives the provider it was written for.
            $this->assertContains(
                'in:'.implode(',', $providers->names($family)),
                $setting->rules,
                sprintf('[%s] is not constrained to the names the screen offers.', $variable),
            );
        }
    }

    #[Test]
    public function the_screen_offers_the_provider_as_a_field_and_not_only_as_a_word(): void
    {
        // Published as `selected` alone, a provider is something to read. As a
        // variable, it is something to change — and the screen renders an input
        // for exactly the variables the writer owns.
        foreach ([
            'ai' => 'SANITUBE_AI_PROVIDER',
            'generation' => 'SANITUBE_GENERATION_PROVIDER',
            'distribution' => 'SANITUBE_DISTRIBUTOR',
        ] as $section => $variable) {
            $this->assertContains($variable, $this->variablesIn($section));
        }
    }

    // ------------------------------------------------------------- writing

    #[Test]
    public function a_provider_can_be_switched_and_the_choice_lands(): void
    {
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_GENERATION_PROVIDER' => 'acestep'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'SANITUBE_GENERATION_PROVIDER=acestep',
            (string) file_get_contents($this->sandbox.'/.env'),
        );
    }

    #[Test]
    public function a_provider_this_build_has_no_code_for_is_refused(): void
    {
        // The failure this constraint prevents is not a typo. It is an
        // installation whose storage, AI or generation resolves to nothing on
        // the next request, reported nowhere until somebody tries to use it.
        foreach ([
            'SANITUBE_GENERATION_PROVIDER' => 'suno',
            'SANITUBE_AI_PROVIDER' => 'whatever',
            'SANITUBE_DISTRIBUTOR' => 'not-a-distributor',
        ] as $variable => $value) {
            $this->actingAs($this->owner())
                ->patch('/settings', [$variable => $value])
                ->assertSessionHasErrors($variable);
        }

        $environment = (string) file_get_contents($this->sandbox.'/.env');

        $this->assertStringContainsString('SANITUBE_GENERATION_PROVIDER=none', $environment);
        $this->assertStringContainsString('SANITUBE_AI_PROVIDER=none', $environment);
        $this->assertStringContainsString('SANITUBE_DISTRIBUTOR=none', $environment);
    }

    #[Test]
    public function the_ai_credentials_offered_are_the_selected_providers_and_no_others(): void
    {
        config(['ai.default' => 'claude']);

        $variables = $this->variablesIn('ai');

        $this->assertContains('SANITUBE_CLAUDE_KEY', $variables);

        // The failure the union test could not see: writable in every
        // configuration, visible in one. An operator on Claude was offered
        // nothing for OpenAI, and OpenAI's key was writable anyway.
        $this->assertNotContains('SANITUBE_OPENAI_KEY', $variables);
        $this->assertNull($this->app->make(WritableSettings::class)->find('SANITUBE_OPENAI_KEY'));
    }

    #[Test]
    public function media_execution_is_a_preference_an_operator_may_set(): void
    {
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_MEDIA_EXECUTION' => 'local'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'SANITUBE_MEDIA_EXECUTION=local',
            (string) file_get_contents($this->sandbox.'/.env'),
        );

        // Closed to the three strategies that exist. An unrecognised value
        // falls back to `auto` at read time, so a free-text field would be a
        // field that silently ignores what was typed into it.
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_MEDIA_EXECUTION' => 'gpu'])
            ->assertSessionHasErrors('SANITUBE_MEDIA_EXECUTION');
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function sectionNamed(string $key): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->app->make(SettingsQuery::class)->overview()['sections'];

        foreach ($sections as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail(sprintf('No [%s] section in the payload.', $key));
    }

    /**
     * @return list<string>
     */
    private function variablesIn(string $key): array
    {
        $section = $this->sectionNamed($key);
        $variables = [];

        foreach ([...(array) $section['settings'], ...(array) $section['secrets']] as $entry) {
            $variables[] = (string) $entry['variable'];
        }

        return $variables;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
