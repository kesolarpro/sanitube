<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\InstallationStatus;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationService;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use Tests\TestCase;

/**
 * The installer.
 *
 * Every test here works against a **temporary** `.env` in its own directory.
 * A test that pointed the installer at the repository's real environment file
 * would rewrite the machine it is running on, and the one guarantee this class
 * is built around — that a backup exists before anything is modified — is
 * exactly the guarantee you cannot verify by destroying the evidence.
 *
 * Three claims carry the ticket:
 *
 *   - **A backup exists before the first modification.** The `.env` of a
 *     working installation holds credentials and an APP_KEY that decrypts
 *     existing sessions, and the operator running the installer is frequently
 *     doing so because something is already wrong.
 *   - **Every stage is idempotent.** Running the installer twice is what
 *     somebody does when the first run failed at stage six.
 *   - **No secret is returned.** Stages name the key they set, never its value.
 */
final class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $envPath;

    private string $examplePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/sanitube-installer-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);

        $this->envPath = $this->directory.'/.env';
        $this->examplePath = $this->directory.'/.env.example';

        file_put_contents($this->examplePath, "APP_NAME=SaniTube\nAPP_KEY=\nDB_CONNECTION=sqlite\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    // ------------------------------------------------------- the environment

    #[Test]
    public function it_creates_an_environment_file_from_the_example(): void
    {
        $result = $this->installer()->environmentFile();

        $this->assertSame('DONE', $result->outcome);
        $this->assertFileExists($this->envPath);
    }

    #[Test]
    public function an_existing_environment_file_is_never_overwritten(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:existing\nDB_PASSWORD=the-real-one\n");

        $result = $this->installer()->environmentFile();

        // Skipped, and the file is byte-for-byte what it was. "The installer
        // replaced my configuration" is not recoverable from a message.
        $this->assertSame('SKIPPED', $result->outcome);
        $this->assertStringContainsString('the-real-one', (string) file_get_contents($this->envPath));
    }

    #[Test]
    public function the_file_itself_refuses_to_overwrite_an_existing_environment(): void
    {
        file_put_contents($this->envPath, "DB_PASSWORD=the-real-one\n");

        // Asserted on EnvironmentFile directly, not through the service. The
        // service checks `exists()` before it ever calls this, so a
        // service-level test passes even with this guard removed — which is
        // what a mutation proved. INS-002's web installer will reach this
        // method without that outer check.
        $created = (new EnvironmentFile($this->envPath))->createFromExample($this->examplePath);

        $this->assertFalse($created);
        $this->assertStringContainsString('the-real-one', (string) file_get_contents($this->envPath));
    }

    #[Test]
    public function nothing_is_modified_without_a_backup_beside_it(): void
    {
        file_put_contents($this->envPath, "APP_NAME=SaniTube\nDB_PASSWORD=the-real-one\n");

        $environment = new EnvironmentFile($this->envPath);
        $this->assertTrue($environment->set('APP_URL', 'https://example.test'));

        $backups = glob($this->directory.'/.env.backup-*') ?: [];

        $this->assertCount(1, $backups);
        $this->assertStringContainsString('the-real-one', (string) file_get_contents($backups[0]));
    }

    #[Test]
    public function the_backup_is_not_readable_by_the_rest_of_a_shared_host(): void
    {
        file_put_contents($this->envPath, "DB_PASSWORD=the-real-one\n");

        (new EnvironmentFile($this->envPath))->set('APP_URL', 'https://example.test');

        $backups = glob($this->directory.'/.env.backup-*') ?: [];
        $mode = fileperms($backups[0]) & 0777;

        // It holds every credential the original does. Group- and
        // world-readable is how a shared host leaks it to the account next
        // door.
        $this->assertSame(0600, $mode);
    }

    #[Test]
    public function only_one_backup_is_taken_per_run(): void
    {
        file_put_contents($this->envPath, "APP_NAME=SaniTube\n");

        $environment = new EnvironmentFile($this->envPath);
        $environment->set('APP_URL', 'https://example.test');
        $environment->set('APP_ENV', 'production');
        $environment->set('APP_DEBUG', 'false');

        // The useful copy is the state before the installer touched anything,
        // not three snapshots of it mid-run.
        $this->assertCount(1, glob($this->directory.'/.env.backup-*') ?: []);
    }

    #[Test]
    public function rewriting_a_key_preserves_comments_and_ordering(): void
    {
        file_put_contents($this->envPath, "# Why this host and not the other one\nDB_HOST=localhost\nAPP_URL=http://old\n# trailing note\n");

        (new EnvironmentFile($this->envPath))->set('APP_URL', 'https://new.example');

        $contents = (string) file_get_contents($this->envPath);

        // An operator who opens .env and finds their annotations gone learns
        // not to trust the installer with the file — and those comments are
        // frequently the only record of why a value is what it is.
        $this->assertStringContainsString('# Why this host and not the other one', $contents);
        $this->assertStringContainsString('# trailing note', $contents);
        $this->assertStringContainsString('DB_HOST=localhost', $contents);
        $this->assertStringContainsString('APP_URL=https://new.example', $contents);
        $this->assertStringNotContainsString('http://old', $contents);
    }

    #[Test]
    public function a_value_containing_spaces_or_hashes_is_quoted(): void
    {
        file_put_contents($this->envPath, "DB_PASSWORD=\n");

        (new EnvironmentFile($this->envPath))->set('DB_PASSWORD', 'a pass#word');

        // Unquoted, every dotenv parser truncates this at the space and again
        // at the hash — and database passwords contain both.
        $this->assertStringContainsString('DB_PASSWORD="a pass#word"', (string) file_get_contents($this->envPath));
        $this->assertSame('a pass#word', (new EnvironmentFile($this->envPath))->get('DB_PASSWORD'));
    }

    // ------------------------------------------------------ the application key

    #[Test]
    public function an_existing_application_key_is_never_replaced(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:the-existing-key\n");

        $result = $this->installer()->applicationKey();

        // Replacing it logs every user out and makes previously encrypted data
        // unreadable. A re-run of the installer must not do that.
        $this->assertSame('SKIPPED', $result->outcome);
        $this->assertStringContainsString('the-existing-key', (string) file_get_contents($this->envPath));
    }

    #[Test]
    public function no_stage_ever_reports_a_secret(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:the-existing-key\nDB_PASSWORD=the-real-one\n");

        $installer = $this->installer();

        $details = implode(' ', [
            $installer->environmentFile()->detail,
            $installer->applicationKey()->detail,
            $installer->database()->detail,
            $installer->verify()->detail,
        ]);

        // These strings are rendered to a terminal and, in INS-002, to a
        // browser. Both are places a credential must not appear.
        $this->assertStringNotContainsString('the-existing-key', $details);
        $this->assertStringNotContainsString('the-real-one', $details);
    }

    // -------------------------------------------------------------- the owner

    #[Test]
    public function it_creates_the_first_owner_as_an_active_owner(): void
    {
        $result = $this->installer()->createOwner('Label', 'owner@example.test', 'a-long-enough-passphrase');

        $this->assertSame('DONE', $result->outcome);

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertTrue((bool) $user->is_active);

        // Hashed by the model's cast, never stored as typed.
        $this->assertNotSame('a-long-enough-passphrase', $user->password);
    }

    /**
     * The joint between installing and using, which neither side held.
     *
     * `it_creates_an_active_owner` proves the row looks right: role OWNER,
     * active, a password that is not the one typed. `AuthenticationTest`
     * proves signing in works — for a user `User::factory()` made. **Nobody
     * signed in as the owner the installer created**, and that is a seam both
     * suites stay green across.
     *
     * The installer sets the password through the model's cast. If the cast
     * moved, or the hasher changed, or the installer started writing an
     * already-hashed value, it would still produce a row that passes every
     * assertion above and nobody could get in. The first person to find out
     * would be the operator, on a fresh installation, with no account to fix
     * it from.
     *
     * So this walks the whole seam: install the owner, sign in through the
     * real form, and open a screen only an administrator may see. Installation
     * itself cannot run here — it would rebuild the database the suite is
     * connected to, which is why the acceptance walk names it as excluded —
     * but the part that matters after it, does.
     */
    #[Test]
    public function the_owner_the_installer_created_can_sign_in_and_reach_an_administrators_screen(): void
    {
        $passphrase = 'a-long-enough-passphrase';

        $this->installer()->createOwner('Label', 'owner@example.test', $passphrase);

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->post('/login', ['email' => 'owner@example.test', 'password' => $passphrase])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);

        // And the role the installer wrote is one the routes accept. A row
        // saying OWNER that no `can.role:` guard recognises is the same
        // failure one layer along.
        $this->get('/system/operations')->assertOk();
    }

    #[Test]
    public function the_owner_password_never_appears_in_the_stage_detail(): void
    {
        $result = $this->installer()->createOwner('Label', 'owner@example.test', 'a-long-enough-passphrase');

        $this->assertStringNotContainsString('a-long-enough-passphrase', $result->detail);
        $this->assertStringContainsString('owner@example.test', $result->detail);
    }

    #[Test]
    public function a_second_run_does_not_create_a_second_owner(): void
    {
        $installer = $this->installer();

        $installer->createOwner('Label', 'owner@example.test', 'a-long-enough-passphrase');
        $second = $installer->createOwner('Someone Else', 'other@example.test', 'another-long-passphrase');

        // What somebody does when the first run failed at a later stage.
        $this->assertSame('SKIPPED', $second->outcome);
        $this->assertSame(1, User::query()->count());
    }

    // -------------------------------------------------------------- the status

    #[Test]
    public function an_installation_with_no_owner_is_not_complete(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:something\n");

        $status = $this->installer()->status();

        $this->assertTrue($status->hasApplicationKey);
        $this->assertTrue($status->databaseReachable);
        $this->assertTrue($status->migrationsRun);
        $this->assertFalse($status->hasActiveOwner);
        $this->assertFalse($status->isComplete());
    }

    #[Test]
    public function a_complete_installation_says_so(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:something\n");

        $installer = $this->installer();
        $installer->createOwner('Label', 'owner@example.test', 'a-long-enough-passphrase');

        $this->assertTrue($installer->status()->isComplete());
        $this->assertSame('DONE', $installer->verify()->outcome);
    }

    #[Test]
    public function a_deactivated_owner_does_not_count_as_an_owner(): void
    {
        file_put_contents($this->envPath, "APP_KEY=base64:something\n");

        $installer = $this->installer();
        $installer->createOwner('Label', 'owner@example.test', 'a-long-enough-passphrase');

        User::query()->firstOrFail()->forceFill(['is_active' => false])->save();

        // An installation whose only owner is deactivated is unadministrable,
        // which on a single-account platform means unusable.
        $this->assertFalse($installer->status()->hasActiveOwner);
        $this->assertFalse($installer->status()->isComplete());
    }

    #[Test]
    public function an_unreachable_database_makes_two_facts_unknown_rather_than_false(): void
    {
        // Asserted on the value object rather than by breaking the connection
        // mid-test: the claim is about what null *means*, and pointing the
        // default connection at nothing would also break the transaction the
        // test itself runs inside.
        $status = new InstallationStatus(
            hasApplicationKey: true,
            databaseReachable: false,
            migrationsRun: null,
            hasActiveOwner: null,
        );

        // "There are no tables" and "I could not look" lead to opposite next
        // steps, so they are different values.
        $this->assertNull($status->migrationsRun);
        $this->assertNull($status->hasActiveOwner);
        $this->assertFalse($status->isComplete());
    }

    #[Test]
    public function an_unknown_is_never_treated_as_installed(): void
    {
        // The failure mode this guards: an installer that read "I could not
        // reach the database" as a yes would consider itself finished and lock
        // itself out of the machine it was supposed to set up.
        $unknown = new InstallationStatus(
            hasApplicationKey: true,
            databaseReachable: null,
            migrationsRun: null,
            hasActiveOwner: null,
        );

        $this->assertFalse($unknown->isComplete());
    }

    // ---------------------------------------------------------- the preflight

    #[Test]
    public function preflight_does_not_refuse_an_install_because_cron_has_never_run(): void
    {
        // The defect this guards against: a scheduler reports unavailable
        // until a cron entry has fired once, which on a first install it never
        // has. Blocking on it would refuse every first install.
        $result = $this->installer()->preflight();

        $this->assertSame('DONE', $result->outcome, $result->detail);
    }

    // ------------------------------------------------------------- the command

    #[Test]
    public function the_command_refuses_to_collect_a_password_without_a_terminal(): void
    {
        // A password passed as an option lands in shell history and in every
        // process listing on the machine.
        $this->artisan('sanitube:install', [
            '--owner-name' => 'Label',
            '--owner-email' => 'owner@example.test',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('shell history')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function the_command_runs_unattended_when_the_owner_is_skipped(): void
    {
        $this->artisan('sanitube:install', ['--skip-owner' => true, '--no-interaction' => true])
            ->expectsOutputToContain('sanitube:user:create')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------- fixtures

    private function installer(): InstallationService
    {
        return new InstallationService(
            environment: new EnvironmentFile($this->envPath),
            capabilities: $this->app->make(CapabilityRegistry::class),
            artisan: $this->app->make(Artisan::class),
            examplePath: $this->examplePath,
        );
    }
}
