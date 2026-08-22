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

        // CFG-002. The fixture's .env describes an installation using OpenAI,
        // so its configuration has to say so too. Before the AI variables were
        // gated on the selection, this fixture could name a provider's
        // credentials while the platform was configured to use no provider at
        // all — writable, invisible on the screen, and read by nothing.
        config(['ai.default' => 'openai']);

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

    /**
     * Variables the screen publishes on purpose without offering to write them,
     * each with the reason it is read-only.
     *
     * An exemption is a line with a sentence. A read-only setting is sometimes
     * right; an unexplained one never is.
     *
     * @var array<string, string>
     */
    private const READ_ONLY_ON_PURPOSE = [
        // The API prefix is in every published client URL. Changing it from a
        // form breaks every integration already pointed at this installation,
        // silently, at the moment of saving something else.
        'SANITUBE_API_PREFIX' => 'Changing it breaks every client already pointed here.',
    ];

    #[Test]
    public function the_screen_and_the_writer_name_the_same_variables_in_every_configuration(): void
    {
        // Two lists that can drift are two lists that will, and the failure
        // mode is a field an operator can change and cannot see the state of.
        //
        // **Configuration by configuration, and in both directions.** A union
        // was the weaker half: it proved nothing was writable that *no* screen
        // ever shows, which let a variable be writable in every configuration
        // and visible in one. CFG-002 found exactly that -- the AI credentials
        // were owned by the writer unconditionally while the screen published
        // them only for the selected provider, so on a `claude` installation
        // the two OpenAI variables were writable and invisible.
        //
        // The other direction is the one that has no other test at all: a
        // variable the screen publishes and nobody can write is a field that
        // looks editable and refuses. Its exemptions are named above.
        $configurations = [
            ['storage.default' => 's3', 'ai.default' => 'openai', 'generation.default' => 'none', 'distribution.default' => 'none'],
            ['storage.default' => 'r2', 'ai.default' => 'claude', 'generation.default' => 'fake', 'distribution.default' => 'fake'],
            ['storage.default' => 'b2', 'ai.default' => 'none', 'generation.default' => 'acestep', 'distribution.default' => 'none'],
            ['storage.default' => 'local', 'ai.default' => 'openai', 'generation.default' => 'fake', 'distribution.default' => 'none'],
        ];

        foreach ($configurations as $configuration) {
            $published = $this->publishedVariables($configuration);
            $writable = $this->app->make(WritableSettings::class)->variables();
            $describe = json_encode($configuration);

            foreach ($writable as $variable) {
                $this->assertContains(
                    $variable,
                    $published,
                    sprintf('[%s] can be written and does not appear on the screen under %s.', $variable, $describe),
                );
            }

            foreach ($published as $variable) {
                if (array_key_exists($variable, self::READ_ONLY_ON_PURPOSE)) {
                    continue;
                }

                $this->assertContains(
                    $variable,
                    $writable,
                    sprintf(
                        '[%s] appears on the screen under %s and nothing can write it. Make it writable, or name it in READ_ONLY_ON_PURPOSE with the reason.',
                        $variable,
                        $describe,
                    ),
                );
            }
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
