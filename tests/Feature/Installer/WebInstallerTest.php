<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationLock;
use Tests\TestCase;

/**
 * Installing SaniTube from a browser.
 *
 * A web installer has one problem no other screen has: **before installation
 * there are no accounts, so there is nobody to authenticate.** Every guard the
 * platform has depends on the thing being set up. Three claims carry these
 * tests, and the first is the one that makes the rest worth having:
 *
 *   - **Reaching the URL is not enough.** The token is readable only from the
 *     filesystem, so installing requires being on the server.
 *   - **The door closes and stays closed.** The lock is permanent, and an
 *     installation that is already complete refuses regardless of any file.
 *   - **Nothing typed here is ever rendered back.** Not on success, not on a
 *     refusal.
 *
 * The environment file and the lock are pointed at a temporary directory. A
 * test that could rewrite the real `.env` is a test nobody should run twice.
 */
final class WebInstallerTest extends TestCase
{
    // DatabaseMigrations rather than RefreshDatabase, and the difference
    // matters here: RefreshDatabase wraps each test in a transaction, and this
    // suite drives the installer's own `migrate` stage. Running a migration
    // inside somebody else's open transaction is not a thing SQLite will do,
    // and it is not what happens on a real install either.
    use DatabaseMigrations;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-installer-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        // APP_KEY already set, so the key stage skips rather than calling
        // `key:generate` — which writes through the framework's own path and
        // would rewrite the file this suite is running from.
        file_put_contents($this->sandbox.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n");

        $this->app->bind(EnvironmentFile::class, fn (): EnvironmentFile => new EnvironmentFile($this->sandbox.'/.env'));
        $this->app->bind(InstallationLock::class, fn (): InstallationLock => new InstallationLock($this->sandbox));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);

        parent::tearDown();
    }

    // ----------------------------------------------------------- the guard

    #[Test]
    public function the_installer_renders_while_the_installation_is_incomplete(): void
    {
        // No owner exists, so this installation is not complete however much
        // else is in place.
        $this->get('/install')->assertOk()->assertSee('Install SaniTube');
    }

    #[Test]
    public function a_locked_installer_is_gone_rather_than_forbidden(): void
    {
        // 404, not 403. A 403 would confirm a SaniTube installer once lived
        // here, which is an invitation to come back and look for the lock.
        $this->lock()->lock();

        $this->get('/install')->assertNotFound();
        $this->post('/install', [])->assertNotFound();
    }

    #[Test]
    public function a_complete_installation_refuses_even_with_no_lock_file(): void
    {
        // The guard that matters. A lock file can be deleted by anybody with
        // the access the token demands; an installation with a live owner must
        // refuse regardless of what any file says.
        $this->owner();

        $this->assertFalse($this->lock()->isLocked());

        $this->get('/install')->assertNotFound();
    }

    // ----------------------------------------------------------- the token

    #[Test]
    public function opening_the_page_writes_a_token_only_the_filesystem_can_read(): void
    {
        $response = $this->get('/install')->assertOk();

        $path = $this->sandbox.'/installer/token';

        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        // And it is nowhere in the page. Rendering it would defeat the whole
        // mechanism: the point is that only somebody on the server has it.
        $response->assertDontSee(trim((string) file_get_contents($path)));
    }

    #[Test]
    public function installing_without_the_token_is_refused(): void
    {
        $this->get('/install');

        $this->post('/install', $this->form(token: 'not-the-token'))
            ->assertSessionHasErrors('token');

        $this->assertFalse(User::query()->where('role', UserRole::Owner->value)->exists());
    }

    #[Test]
    public function a_refused_token_writes_nothing(): void
    {
        $this->get('/install');

        $before = (string) file_get_contents($this->sandbox.'/.env');

        $this->post('/install', $this->form(token: 'not-the-token'))
            ->assertSessionHasErrors('token');

        $this->assertSame($before, (string) file_get_contents($this->sandbox.'/.env'));
    }

    #[Test]
    public function the_token_is_refused_when_there_is_none_at_all(): void
    {
        // The absence of a token is not a reason to let somebody in.
        $this->assertFalse($this->lock()->accepts('anything'));
        $this->assertFalse($this->lock()->accepts(''));
    }

    #[Test]
    public function the_form_is_behind_csrf_like_every_other_write(): void
    {
        // Asserted on the route's middleware rather than by posting without a
        // token: Laravel's CSRF middleware stands down inside a test run, so a
        // request that "succeeds" here would prove nothing. The stack is the
        // fact — and the installer declares its own, which is exactly the kind
        // of place a middleware quietly goes missing.
        $route = Route::getRoutes()->getByName('install.store');

        $this->assertNotNull($route);
        $this->assertContains(ValidateCsrfToken::class, $route->gatherMiddleware());
    }

    // --------------------------------------------------------- the secrets

    #[Test]
    public function a_refusal_does_not_flash_what_was_typed_into_the_session(): void
    {
        // The session, not just the page — and the distinction is the whole
        // test. Today's template reads `old()` for nothing sensitive, so
        // asserting only on the rendered HTML passes even when the controller
        // flashes both passwords: the defect sits in the session waiting for
        // somebody to add `value="{{ old('db_password') }}"`, which is an
        // entirely natural edit to make.
        //
        // Flashing a secret is the defect. Rendering it is the consequence.
        $this->get('/install');

        $this->post('/install', $this->form(token: 'not-the-token'));

        $flashed = json_encode(session('_old_input', []));

        $this->assertStringNotContainsString('a-database-password', (string) $flashed);
        $this->assertStringNotContainsString('an-owner-passphrase', (string) $flashed);

        // And, consequently, nowhere in the page either.
        $this->get('/install')
            ->assertOk()
            ->assertDontSee('a-database-password')
            ->assertDontSee('an-owner-passphrase');
    }

    // ---------------------------------------------------------- installing

    #[Test]
    public function a_valid_token_installs_and_the_door_closes_behind_it(): void
    {
        $this->get('/install');

        $token = trim((string) file_get_contents($this->sandbox.'/installer/token'));

        $this->post('/install', $this->form(token: $token))
            ->assertRedirect(route('login'));

        $owner = User::query()->where('role', UserRole::Owner->value)->first();

        $this->assertInstanceOf(User::class, $owner);
        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue($owner->is_active);

        // Locked, and the token gone with it: leaving a usable credential on
        // disk for a door that is already shut only gives somebody something
        // to find.
        $this->assertTrue($this->lock()->isLocked());
        $this->assertFileDoesNotExist($this->sandbox.'/installer/token');
    }

    #[Test]
    public function what_was_typed_reaches_the_environment_file_and_not_the_response(): void
    {
        $this->get('/install');

        $token = trim((string) file_get_contents($this->sandbox.'/installer/token'));

        $response = $this->post('/install', $this->form(token: $token));

        $environment = (string) file_get_contents($this->sandbox.'/.env');

        // Written, because that is what the form is for.
        $this->assertStringContainsString('a-database-password', $environment);

        // And absent from everything the browser gets back.
        $response->assertDontSee('a-database-password');
        $response->assertDontSee('an-owner-passphrase');
    }

    #[Test]
    public function the_environment_file_is_backed_up_before_it_is_rewritten(): void
    {
        // EnvironmentFile refuses to write without one. This asserts the
        // installer actually goes through it rather than around it.
        $this->get('/install');

        $token = trim((string) file_get_contents($this->sandbox.'/installer/token'));

        $this->post('/install', $this->form(token: $token));

        $this->assertNotEmpty(
            glob($this->sandbox.'/.env.backup-*') ?: [],
            'The .env was rewritten with no copy of what it held beside it.',
        );
    }

    #[Test]
    public function the_installer_cannot_be_run_twice(): void
    {
        $this->get('/install');

        $token = trim((string) file_get_contents($this->sandbox.'/installer/token'));

        $this->post('/install', $this->form(token: $token))->assertRedirect(route('login'));

        // Both guards now hold: the lock is written and the installation is
        // complete. A second owner is not created.
        $this->post('/install', $this->form(token: $token, email: 'somebody-else@example.test'))
            ->assertNotFound();

        $this->assertSame(1, User::query()->where('role', UserRole::Owner->value)->count());
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @return array<string, string>
     */
    private function form(string $token, string $email = 'owner@example.test'): array
    {
        return [
            'token' => $token,
            'app_url' => 'https://sanitube.example.test',
            'db_connection' => 'sqlite',
            'db_database' => ':memory:',
            'db_password' => 'a-database-password',
            'name' => 'The Owner',
            'email' => $email,
            'password' => 'an-owner-passphrase',
            'password_confirmation' => 'an-owner-passphrase',
        ];
    }

    private function lock(): InstallationLock
    {
        return $this->app->make(InstallationLock::class);
    }

    private function owner(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'existing@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $child = $path.'/'.$entry;

            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
