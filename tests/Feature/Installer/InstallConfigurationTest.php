<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Installer\Exceptions\InstallConfigurationException;
use SaniTube\Installer\Http\Controllers\InstallController;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallConfiguration;
use Tests\TestCase;

/**
 * An unattended install, and where its secrets are allowed to live.
 *
 * DEP-009. A VPS bootstrap installs SaniTube without a terminal conversation,
 * which means the database credentials and the owner's identity arrive as a
 * file — and a file of secrets is only acceptable under rules: owner-only
 * permissions or refusal, an allowlist of keys or refusal, and no value ever
 * quoted back in an error. These tests are those rules.
 */
final class InstallConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/install-config-'.uniqid());
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------- parsing

    #[Test]
    public function the_env_dialect_is_understood_comments_quotes_and_all(): void
    {
        $config = InstallConfiguration::fromString(<<<'CONF'
            # Production database
            APP_URL=https://label.example
            DB_CONNECTION=mysql
            DB_PASSWORD="with spaces and #hash"

            OWNER_NAME='The Label'
            OWNER_EMAIL=owner@label.example
            CONF);

        $this->assertSame('https://label.example', $config->environment['APP_URL']);
        $this->assertSame('with spaces and #hash', $config->environment['DB_PASSWORD']);
        $this->assertSame('The Label', $config->ownerName());
        $this->assertSame('owner@label.example', $config->ownerEmail());
        $this->assertNull($config->ownerPassword());
    }

    #[Test]
    public function owner_keys_are_held_apart_from_environment_keys(): void
    {
        // OWNER_* is consumed by the installer and must never be written into
        // .env — an owner password in .env would outlive the install by years.
        $config = InstallConfiguration::fromString("OWNER_EMAIL=o@l.example\nOWNER_PASSWORD=correct-horse-battery\n");

        $this->assertSame([], $config->environment);
        $this->assertSame('correct-horse-battery', $config->ownerPassword());
    }

    #[Test]
    public function an_unknown_key_stops_the_run_instead_of_surfacing_three_stages_later(): void
    {
        try {
            InstallConfiguration::fromString("DB_DATABSE=sanitube\n");
            $this->fail('A typoed key was accepted.');
        } catch (InstallConfigurationException $exception) {
            $this->assertSame('CONFIG_UNKNOWN_KEY', $exception->reason);
            $this->assertStringContainsString('DB_DATABSE', $exception->getMessage());
        }
    }

    #[Test]
    public function a_line_that_is_not_key_value_is_named_by_number_never_by_content(): void
    {
        try {
            InstallConfiguration::fromString("APP_URL=https://a.example\nmysql://root:hunter2@db\n");
            $this->fail('An unparseable line was accepted.');
        } catch (InstallConfigurationException $exception) {
            $this->assertSame('CONFIG_UNPARSEABLE', $exception->reason);
            $this->assertStringContainsString('Line 2', $exception->getMessage());
            $this->assertStringNotContainsString('hunter2', $exception->getMessage());
        }
    }

    #[Test]
    public function the_config_file_may_write_exactly_what_the_web_installer_may_write(): void
    {
        // Two unattended paths, one capability. A key only the file could set
        // would make it a third, more powerful installer; one only the form
        // could set would make "unattended" quietly weaker. The lists are
        // private on purpose — reflection reads them so nothing else can.
        $form = new \ReflectionClassConstant(InstallController::class, 'WRITABLE');
        $file = new \ReflectionClassConstant(InstallConfiguration::class, 'ENVIRONMENT_KEYS');

        /** @var array<string, string> $formList */
        $formList = $form->getValue();
        /** @var list<string> $fileList */
        $fileList = $file->getValue();

        $this->assertSame(array_values($formList), $fileList);
    }

    // --------------------------------------------------------- permissions

    #[Test]
    public function a_file_other_accounts_can_read_is_refused_before_a_byte_is_parsed(): void
    {
        $path = $this->root.'/install.conf';
        file_put_contents($path, "APP_URL=https://label.example\n");
        chmod($path, 0644);

        try {
            InstallConfiguration::fromFile($path);
            $this->fail('A world-readable secrets file was accepted.');
        } catch (InstallConfigurationException $exception) {
            $this->assertSame('CONFIG_TOO_OPEN', $exception->reason);
            $this->assertStringContainsString('chmod 600', $exception->getMessage());
        }
    }

    #[Test]
    public function group_write_disqualifies_just_as_surely_as_world_read(): void
    {
        // Checking only the read bits would bless a file another account
        // could rewrite, which is worse than one it could merely read.
        $path = $this->root.'/install.conf';
        file_put_contents($path, "APP_URL=https://label.example\n");
        chmod($path, 0620);

        try {
            InstallConfiguration::fromFile($path);
            $this->fail('A group-writable secrets file was accepted.');
        } catch (InstallConfigurationException $exception) {
            $this->assertSame('CONFIG_TOO_OPEN', $exception->reason);
        }
    }

    #[Test]
    public function an_owner_only_file_is_read(): void
    {
        $path = $this->root.'/install.conf';
        file_put_contents($path, "APP_URL=https://label.example\n");
        chmod($path, 0600);

        $this->assertSame(
            'https://label.example',
            InstallConfiguration::fromFile($path)->environment['APP_URL'],
        );
    }

    #[Test]
    public function a_missing_file_is_a_named_refusal_not_a_php_warning(): void
    {
        try {
            InstallConfiguration::fromFile($this->root.'/nowhere.conf');
            $this->fail('A missing file was accepted.');
        } catch (InstallConfigurationException $exception) {
            $this->assertSame('CONFIG_MISSING', $exception->reason);
        }
    }

    // ------------------------------------------------------------- command

    #[Test]
    public function an_unattended_install_runs_from_file_to_owner_without_a_prompt(): void
    {
        $env = $this->temporaryEnvironment();

        $config = $this->configFile(<<<'CONF'
            APP_URL=https://label.example
            OWNER_NAME=Unattended Owner
            OWNER_EMAIL=owner@unattended.example
            OWNER_PASSWORD=a-long-enough-password
            CONF);

        $this->artisan('sanitube:install', ['--config' => $config, '--no-interaction' => true])
            ->assertSuccessful();

        // The environment stage wrote the allowlisted key into .env...
        $this->assertSame('https://label.example', (new EnvironmentFile($env))->get('APP_URL'));

        // ...and the owner exists without a terminal having asked anything.
        $owner = User::query()->where('email', 'owner@unattended.example')->first();
        $this->assertNotNull($owner);

        // The owner's password reached the hasher, never .env.
        $this->assertStringNotContainsString('a-long-enough-password', (string) file_get_contents($env));
    }

    #[Test]
    public function the_password_may_come_from_the_process_environment_instead(): void
    {
        $this->temporaryEnvironment();

        $config = $this->configFile("OWNER_NAME=Env Owner\nOWNER_EMAIL=env-owner@label.example\n");

        putenv('SANITUBE_OWNER_PASSWORD=environment-supplied-pass');

        try {
            $this->artisan('sanitube:install', ['--config' => $config, '--no-interaction' => true])
                ->assertSuccessful();
        } finally {
            putenv('SANITUBE_OWNER_PASSWORD');
        }

        $this->assertNotNull(User::query()->where('email', 'env-owner@label.example')->first());
    }

    #[Test]
    public function an_unattended_run_with_no_password_anywhere_names_both_places_it_looked(): void
    {
        $this->temporaryEnvironment();

        $config = $this->configFile("OWNER_NAME=No Pass\nOWNER_EMAIL=nopass@label.example\n");

        $this->artisan('sanitube:install', ['--config' => $config, '--no-interaction' => true])
            ->expectsOutputToContain('SANITUBE_OWNER_PASSWORD')
            ->assertFailed();

        $this->assertNull(User::query()->where('email', 'nopass@label.example')->first());
    }

    #[Test]
    public function a_too_open_config_file_fails_the_run_before_any_stage(): void
    {
        $this->temporaryEnvironment();

        $config = $this->configFile("APP_URL=https://label.example\n");
        chmod($config, 0644);

        $this->artisan('sanitube:install', ['--config' => $config, '--skip-owner' => true])
            ->expectsOutputToContain('chmod 600')
            ->assertFailed();
    }

    #[Test]
    public function skip_owner_still_wins_over_a_config_that_describes_one(): void
    {
        $this->temporaryEnvironment();

        $config = $this->configFile(<<<'CONF'
            OWNER_NAME=Skipped Anyway
            OWNER_EMAIL=skipped@label.example
            OWNER_PASSWORD=a-long-enough-password
            CONF);

        $this->artisan('sanitube:install', ['--config' => $config, '--skip-owner' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertNull(User::query()->where('email', 'skipped@label.example')->first());
    }

    // ----------------------------------------------------------- fixtures

    /**
     * A `.env` of this test's own, so the configuration stage rewrites a
     * fixture and never the checkout's real file. The APP_KEY line makes the
     * key stage skip rather than run `key:generate` — which would write to
     * the application's own environment path, not this one.
     */
    private function temporaryEnvironment(): string
    {
        $path = $this->root.'/.env';
        file_put_contents($path, "APP_NAME=SaniTube\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n");

        $this->app->bind(EnvironmentFile::class, fn (): EnvironmentFile => new EnvironmentFile($path));

        return $path;
    }

    private function configFile(string $content): string
    {
        $path = $this->root.'/install.conf';
        file_put_contents($path, $content."\n");
        chmod($path, 0600);

        return $path;
    }
}
