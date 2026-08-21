<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\WritableSettings;
use Tests\TestCase;

/**
 * Changing configuration from the interface.
 *
 * The read side of this screen has always been careful: a credential is
 * **configured** or **not configured**, never a value, a mask or a length. The
 * write side has to keep that promise while doing the opposite thing, and
 * three claims carry these tests:
 *
 *   - **A blank secret means unchanged.** The field is rendered empty because
 *     the browser is never told what is in it, so a blank submission is what
 *     an operator sends when they edited something else on the page.
 *   - **What may be written is a list, and it is short.** `APP_KEY`,
 *     `APP_DEBUG` and `DB_*` are not on it.
 *   - **Nothing written is reported back.** Not in the response, not in the
 *     audit log.
 *
 * The environment file is pointed at a temporary directory. A test that could
 * rewrite the real `.env` is a test nobody should run twice.
 */
final class SettingsWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-settings-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG=false',
            'DB_DATABASE=the_real_one',
            'SANITUBE_OPENAI_KEY=the-existing-key',
            'SANITUBE_OPENAI_BASE_URL=https://api.openai.test/v1',
            'SANITUBE_API_RATE_LIMIT=60',
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

    // ------------------------------------------------------ authorisation

    #[Test]
    public function a_member_may_read_the_settings_screen_and_change_nothing_on_it(): void
    {
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->get('/settings')->assertForbidden();

        $this->actingAs($member)
            ->patch('/settings', ['SANITUBE_API_RATE_LIMIT' => '120'])
            ->assertForbidden();

        $this->assertStringContainsString('SANITUBE_API_RATE_LIMIT=60', $this->environment());
    }

    // ------------------------------------------------------- blank secrets

    #[Test]
    public function a_blank_secret_leaves_the_existing_one_alone(): void
    {
        // The case that would otherwise erase a provider key every time
        // somebody edited the field next to it. The secret field renders empty
        // on every load, so this is the ordinary submission, not an edge case.
        $this->actingAs($this->user())
            ->patch('/settings', [
                'SANITUBE_OPENAI_KEY' => '',
                'SANITUBE_API_RATE_LIMIT' => '120',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('SANITUBE_OPENAI_KEY=the-existing-key', $this->environment());
        $this->assertStringContainsString('SANITUBE_API_RATE_LIMIT=120', $this->environment());
    }

    #[Test]
    public function replacing_a_secret_is_explicit_and_takes_effect(): void
    {
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_OPENAI_KEY' => 'a-brand-new-key'])
            ->assertRedirect();

        $environment = $this->environment();

        $this->assertStringContainsString('SANITUBE_OPENAI_KEY=a-brand-new-key', $environment);
        $this->assertStringNotContainsString('the-existing-key', $environment);
    }

    #[Test]
    public function a_blank_field_clears_nothing_at_all(): void
    {
        // Not only secrets. Laravel turns empty strings into null before they
        // arrive, so a blank plain setting is indistinguishable from one
        // nobody touched — and rather than defeat that to tell them apart,
        // removal is not offered. A settings form whose blank fields erase
        // configuration is one where saving a rate limit silently unsets a
        // provider endpoint.
        $this->actingAs($this->user())
            ->patch('/settings', [
                'SANITUBE_OPENAI_KEY' => '',
                'SANITUBE_OPENAI_BASE_URL' => '',
            ])
            ->assertSessionHas('status', 'settings.unchanged');

        $this->assertStringContainsString('SANITUBE_OPENAI_KEY=the-existing-key', $this->environment());
        $this->assertStringContainsString('SANITUBE_OPENAI_BASE_URL=https://api.openai.test/v1', $this->environment());
    }

    // --------------------------------------------------------- the allowlist

    #[Test]
    public function nothing_outside_the_list_is_written_however_it_is_submitted(): void
    {
        // Replacing APP_KEY makes existing sessions and any encrypted column
        // unreadable; APP_DEBUG in production puts stack traces in front of
        // whoever triggers an error; DB_DATABASE repoints a running
        // installation. None of the three is a settings field.
        $this->actingAs($this->user())
            ->patch('/settings', [
                'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
                'APP_DEBUG' => 'true',
                'DB_DATABASE' => 'somewhere_else',
                'SANITUBE_API_RATE_LIMIT' => '120',
            ])
            ->assertRedirect();

        $environment = $this->environment();

        $this->assertStringContainsString('APP_DEBUG=false', $environment);
        $this->assertStringContainsString('DB_DATABASE=the_real_one', $environment);
        $this->assertStringNotContainsString('somewhere_else', $environment);
        $this->assertStringNotContainsString(base64_encode(str_repeat('x', 32)), $environment);
    }

    #[Test]
    public function every_writable_variable_is_one_the_screen_actually_shows(): void
    {
        // Two lists that can drift are two lists that will, and the failure
        // mode is a field an operator can change and cannot see the state of.
        // Across every provider selection, because the screen publishes a
        // section's credentials only when that provider is the one in use —
        // reading it in a single configuration would call three quarters of
        // the list invisible.
        //
        // A union is the weaker half of the guarantee: it proves nothing is
        // writable that no screen ever shows. That the *storage* half agrees
        // configuration by configuration — which is what STO-004 broke — is
        // asserted in StorageSettingsTest, where a union would be no proof at
        // all.
        $published = [
            ...$this->publishedVariables(['storage.default' => 's3', 'ai.default' => 'openai']),
            ...$this->publishedVariables(['storage.default' => 'r2', 'ai.default' => 'claude']),
            ...$this->publishedVariables(['storage.default' => 'b2', 'ai.default' => 'openai']),
            ...$this->publishedVariables(['storage.default' => 'local', 'ai.default' => 'claude']),
        ];

        foreach ($this->app->make(WritableSettings::class)->variables() as $variable) {
            $this->assertContains(
                $variable,
                $published,
                sprintf('[%s] can be written but never appears on the settings screen.', $variable),
            );
        }
    }

    // ------------------------------------------------------------ validation

    #[Test]
    public function a_value_outside_its_bounds_is_refused_and_writes_nothing(): void
    {
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_GENERATION_MAX_POLLS' => '99999'])
            ->assertSessionHasErrors('SANITUBE_GENERATION_MAX_POLLS');

        $this->assertStringNotContainsString('99999', $this->environment());
    }

    // --------------------------------------------------------------- secrecy

    #[Test]
    public function the_audit_line_names_the_variable_and_never_the_value(): void
    {
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_OPENAI_KEY' => 'a-brand-new-key']);

        $event = AuditEvent::query()->where('action', AuditAction::SettingsChanged->value)->first();

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertStringContainsString('SANITUBE_OPENAI_KEY', (string) ((array) $event->context)['variables']);

        foreach ((array) $event->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString(
                'a-brand-new-key',
                (string) $value,
                sprintf('[%s] holds the value that was written.', $column),
            );
        }
    }

    #[Test]
    public function the_response_reports_a_change_without_naming_what_it_was(): void
    {
        $response = $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_OPENAI_KEY' => 'a-brand-new-key'])
            ->assertRedirect();

        $response->assertSessionHas('status', 'settings.saved');
        $response->assertSessionMissing('_old_input');

        $this->followRedirects($response)->assertDontSee('a-brand-new-key');
    }

    #[Test]
    public function saving_nothing_says_so_rather_than_claiming_a_change(): void
    {
        // An operator who typed into a secret field and then submitted a blank
        // one by accident needs to be told the difference.
        $this->actingAs($this->user())
            ->patch('/settings', ['SANITUBE_OPENAI_KEY' => '', 'SANITUBE_API_RATE_LIMIT' => '60'])
            ->assertSessionHas('status', 'settings.unchanged');

        $this->assertSame(0, AuditEvent::query()->where('action', AuditAction::SettingsChanged->value)->count());
    }

    // ------------------------------------------------------------- the file

    #[Test]
    public function the_file_is_backed_up_before_it_is_rewritten(): void
    {
        $this->actingAs($this->user())->patch('/settings', ['SANITUBE_API_RATE_LIMIT' => '120']);

        $backups = glob($this->sandbox.'/.env.backup-*') ?: [];

        $this->assertNotEmpty($backups, 'The .env was rewritten with no copy of what it held beside it.');
        $this->assertStringContainsString('SANITUBE_API_RATE_LIMIT=60', (string) file_get_contents((string) $backups[0]));
    }

    #[Test]
    public function a_write_that_fails_leaves_the_file_exactly_as_it_was(): void
    {
        // The reason several changes are one write. A failure partway through
        // separate writes leaves an installation configured half one way and
        // half the other — a database name from the new settings beside
        // credentials from the old.
        //
        // Backup count is *not* the signal: EnvironmentFile takes one backup
        // per run whether it writes once or four times, which an earlier
        // version of this test asserted on and a mutation walked straight
        // through.
        $before = $this->environment();

        // An unwritable path rather than an unwritable file: the test suite
        // frequently runs as root, and root writes read-only files happily —
        // a chmod-based version of this passes locally and proves nothing in
        // any container.
        $this->app->bind(
            EnvironmentFile::class,
            fn (): EnvironmentFile => new EnvironmentFile($this->sandbox.'/nowhere/.env'),
        );

        $this->actingAs($this->user())
            ->patch('/settings', [
                'SANITUBE_API_RATE_LIMIT' => '120',
                'SANITUBE_OPENAI_KEY' => 'a-brand-new-key',
            ])
            ->assertSessionHasErrors('settings');

        // Refused, and nothing recorded — an audit line about a change that
        // did not happen is worse than no line at all.
        $this->assertSame($before, $this->environment());
        $this->assertSame(0, AuditEvent::query()->where('action', AuditAction::SettingsChanged->value)->count());
    }

    #[Test]
    public function several_changes_all_land(): void
    {
        $this->actingAs($this->user())->patch('/settings', [
            'SANITUBE_API_RATE_LIMIT' => '120',
            'SANITUBE_GENERATION_MAX_POLLS' => '40',
            'SANITUBE_OPENAI_KEY' => 'a-brand-new-key',
        ]);

        $environment = $this->environment();

        $this->assertStringContainsString('SANITUBE_API_RATE_LIMIT=120', $environment);
        $this->assertStringContainsString('SANITUBE_GENERATION_MAX_POLLS=40', $environment);
        $this->assertStringContainsString('SANITUBE_OPENAI_KEY=a-brand-new-key', $environment);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function publishedVariables(array $configuration = []): array
    {
        config($configuration);

        $overview = $this->app->make(SettingsQuery::class)->overview();
        $variables = [];

        /** @var list<array<string, mixed>> $sections */
        $sections = $overview['sections'];

        foreach ($sections as $section) {
            foreach ([...(array) $section['settings'], ...(array) $section['secrets']] as $entry) {
                $variables[] = (string) $entry['variable'];
            }
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
