<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\SettingsQuery;
use Tests\TestCase;

/**
 * The settings shell.
 *
 * One claim above all others: **no credential value ever reaches this screen.**
 * Not masked, not truncated, not its length. A masked secret is a secret with
 * the guessable part left in — a length narrows a search and a suffix confirms
 * a guess — so the payload carries a boolean and nothing else.
 *
 * Three further claims:
 *
 *   - **Administrator-only**, even though it publishes no secret. The list of
 *     which credentials are missing is itself a map of where the soft spots
 *     are.
 *   - **Not configured and unavailable are different.** An empty variable is a
 *     fact about this machine; a provider name with no adapter behind it is a
 *     configuration mistake, and they are fixed differently.
 *   - **Nothing is probed.** Rendering this page makes no provider call and no
 *     bucket round trip. It is the page somebody opens to find out what is
 *     set, and an outage must not take it down.
 */
final class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    /** A value shaped like a real key, so a partial leak is findable. */
    private const SECRET = 'sk-live-4f9a2c7e11b84d6fa0c3e5d7b2914806';

    // --------------------------------------------------------------- access

    #[Test]
    public function settings_are_behind_authentication(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));
    }

    #[Test]
    public function only_an_administrator_may_open_settings(): void
    {
        // The list of which credentials are missing is a map of where the soft
        // spots are, whatever the values are.
        $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->get('/settings')
            ->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin, 'admin@example.test'))
            ->get('/settings')
            ->assertOk();
    }

    #[Test]
    public function a_deactivated_administrator_loses_settings_at_the_next_request(): void
    {
        $admin = $this->user(UserRole::Admin, 'admin@example.test');
        $admin->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->get('/settings')->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------- leakage

    #[Test]
    public function no_credential_value_reaches_the_payload(): void
    {
        $this->withSecrets();

        $encoded = json_encode($this->overview(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SECRET, $encoded);

        // Not the whole value, and not a piece of it either. A prefix says
        // which provider issued it; a suffix confirms a guess.
        $this->assertStringNotContainsString(substr(self::SECRET, 0, 8), $encoded);
        $this->assertStringNotContainsString(substr(self::SECRET, -6), $encoded);
    }

    #[Test]
    public function no_credential_value_reaches_the_rendered_page(): void
    {
        $this->withSecrets();

        $response = $this->actingAs($this->user(UserRole::Owner, 'owner@example.test'))->get('/settings');

        $response->assertOk();
        $response->assertDontSee(self::SECRET, escape: false);
        $response->assertDontSee(substr(self::SECRET, 0, 8), escape: false);
    }

    #[Test]
    public function a_secret_carries_no_length_and_no_value_field(): void
    {
        $this->withSecrets();

        $secret = $this->secretNamed('SANITUBE_OPENAI_KEY');

        $this->assertTrue($secret['configured']);
        $this->assertTrue($secret['secret']);

        // A length is not a value and is still an advantage: it tells an
        // attacker which key format they are looking at.
        $this->assertArrayNotHasKey('value', $secret);
        $this->assertArrayNotHasKey('length', $secret);
        $this->assertArrayNotHasKey('masked', $secret);
        $this->assertArrayNotHasKey('preview', $secret);
    }

    #[Test]
    public function an_absent_credential_is_reported_as_absent(): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => null]);

        $this->assertFalse($this->secretNamed('SANITUBE_OPENAI_KEY')['configured']);
    }

    #[Test]
    public function whitespace_is_not_a_configured_credential(): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => '   ']);

        // A variable somebody left blank while editing is not a credential,
        // and reporting it as one is how an installation looks ready and is
        // not.
        $this->assertFalse($this->secretNamed('SANITUBE_OPENAI_KEY')['configured']);
    }

    // ------------------------------------------ not configured vs unavailable

    #[Test]
    public function a_provider_with_no_adapter_is_reported_as_unknown_not_as_missing(): void
    {
        config(['ai.default' => 'a-provider-that-does-not-exist']);

        $section = $this->sectionNamed('ai');

        // Two different mistakes with two different fixes: nobody has entered
        // a key, versus somebody entered a name nothing matches.
        $this->assertFalse($section['known']);
        $this->assertFalse($section['complete']);
    }

    #[Test]
    public function an_unknown_storage_provider_is_reported_as_unknown_too(): void
    {
        // Every section computes `known` for itself, so every section needs
        // its own test. A mutation that hard-coded the storage section's
        // answer survived a suite that only checked the AI one.
        config(['storage.default' => 'a-disk-that-does-not-exist']);

        $section = $this->sectionNamed('storage');

        $this->assertFalse($section['known']);
        $this->assertFalse($section['complete']);
    }

    #[Test]
    public function a_provider_that_needs_no_credentials_is_complete(): void
    {
        config(['ai.default' => 'none']);

        $section = $this->sectionNamed('ai');

        $this->assertTrue($section['known']);
        $this->assertSame([], $section['secrets']);

        // "Disabled" is a legitimate, finished configuration, not an
        // incomplete one. Reporting it as missing something would teach an
        // operator to ignore the flag.
        $this->assertTrue($section['complete']);
    }

    #[Test]
    public function local_storage_needs_no_credentials(): void
    {
        config(['storage.default' => 'local']);

        $this->assertSame([], $this->sectionNamed('storage')['secrets']);
    }

    #[Test]
    public function a_configured_credential_survives_a_cached_configuration(): void
    {
        // The bug PHPStan caught before a person did: reading presence from
        // `env()` reports every credential as missing the moment an
        // installation runs `config:cache`, which every production one should.
        // Setting the value in config alone — with nothing in the environment —
        // has to be enough.
        putenv('SANITUBE_OPENAI_KEY');
        unset($_ENV['SANITUBE_OPENAI_KEY'], $_SERVER['SANITUBE_OPENAI_KEY']);

        config(['ai.default' => 'openai', 'ai.providers.openai.key' => self::SECRET]);

        // Nothing in the environment, everything in config — which is exactly
        // the shape of a `config:cache`d installation.
        $this->assertFalse(is_string(getenv('SANITUBE_OPENAI_KEY')));
        $this->assertTrue($this->secretNamed('SANITUBE_OPENAI_KEY')['configured']);
    }

    #[Test]
    public function remote_storage_lists_what_it_needs(): void
    {
        config(['storage.default' => 's3']);

        $variables = array_column($this->sectionNamed('storage')['secrets'], 'variable');

        $this->assertContains('AWS_ACCESS_KEY_ID', $variables);
        $this->assertContains('AWS_SECRET_ACCESS_KEY', $variables);
    }

    #[Test]
    public function each_storage_provider_is_read_from_its_own_disk(): void
    {
        // r2 and b2 are S3-compatible and configured on their own disks.
        // Reading s3's credentials for all three would report a working b2
        // installation as unconfigured — and reading a *configured* s3 disk
        // would report an empty r2 one as ready.
        config([
            'storage.default' => 'r2',
            'filesystems.disks.s3.key' => self::SECRET,
            'filesystems.disks.s3.secret' => self::SECRET,
            'filesystems.disks.s3.bucket' => 'wrong-bucket',
            'filesystems.disks.r2.key' => null,
            'filesystems.disks.r2.secret' => null,
            'filesystems.disks.r2.bucket' => null,
        ]);

        $section = $this->sectionNamed('storage');

        $this->assertFalse($section['complete']);

        foreach ($section['secrets'] as $secret) {
            $this->assertFalse($secret['configured'], $secret['variable'].' was read from the wrong disk.');
        }
    }

    #[Test]
    public function a_section_is_incomplete_while_anything_it_needs_is_missing(): void
    {
        config(['ai.default' => 'claude', 'ai.providers.claude.key' => null]);

        $section = $this->sectionNamed('ai');

        $this->assertTrue($section['known']);
        $this->assertFalse($section['complete']);
    }

    // ------------------------------------------------------ what is published

    #[Test]
    public function the_selected_provider_is_published_because_it_is_provenance(): void
    {
        config(['storage.default' => 's3']);

        // Not a secret: a label has to know whose service holds their masters.
        $this->assertSame('s3', $this->sectionNamed('storage')['selected']);
    }

    #[Test]
    public function debug_mode_is_reported_because_being_wrong_about_it_is_invisible(): void
    {
        config(['app.debug' => true]);

        $this->assertTrue($this->overview()['application']['debug']);
    }

    #[Test]
    public function the_page_says_whether_the_configuration_cache_is_built(): void
    {
        // Editing .env changes nothing while the cache stands, and nothing
        // else in the interface says so.
        $this->assertArrayHasKey('config_cached', $this->overview()['application']);
    }

    #[Test]
    public function settings_are_reachable_from_the_navigation_for_an_administrator(): void
    {
        $tree = (new NavigationTree)->for($this->user(UserRole::Admin, 'admin@example.test'));

        $settings = null;

        foreach ($tree as $item) {
            if ($item['key'] === 'settings') {
                $settings = $item;
            }
        }

        $this->assertNotNull($settings);
        $this->assertTrue($settings['available']);
        $this->assertSame('/settings', $settings['href']);
    }

    #[Test]
    public function a_member_is_not_offered_settings_in_the_navigation(): void
    {
        $tree = (new NavigationTree)->for($this->user(UserRole::Member, 'member@example.test'));

        foreach ($tree as $item) {
            if ($item['key'] === 'settings') {
                // Presentation only — the route middleware is the guard — but
                // offering a link that will 403 makes people distrust the
                // interface.
                $this->assertFalse($item['available']);
                $this->assertNull($item['href']);
            }
        }
    }

    // -------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        return $this->app->make(SettingsQuery::class)->overview();
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionNamed(string $key): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->overview()['sections'];

        foreach ($sections as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail(sprintf('No [%s] section in the payload.', $key));
    }

    /**
     * @return array<string, mixed>
     */
    private function secretNamed(string $variable): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->overview()['sections'];

        foreach ($sections as $section) {
            /** @var list<array<string, mixed>> $secrets */
            $secrets = $section['secrets'];

            foreach ($secrets as $secret) {
                if ($secret['variable'] === $variable) {
                    return $secret;
                }
            }
        }

        $this->fail(sprintf('No [%s] secret in the payload.', $variable));
    }

    private function withSecrets(): void
    {
        // Set through config, because that is what the query reads. An
        // installation that has run `config:cache` gets null from `env()` for
        // everything, and a settings screen built on `env()` would report a
        // working platform as entirely unconfigured.
        config([
            'ai.default' => 'openai',
            'ai.providers.openai.key' => self::SECRET,
            'storage.default' => 's3',
            'filesystems.disks.s3.secret' => self::SECRET,
            'sanitube.api.internal_token' => self::SECRET,
        ]);
    }

    private function user(UserRole $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true]);
    }
}
