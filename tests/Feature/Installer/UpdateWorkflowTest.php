<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Installer\Services\DeploymentLock;
use Tests\TestCase;

/**
 * One deploy at a time, and an update that refuses to guess.
 *
 * DEP-012. The lock is what stands between two `sanitube:deploy` runs
 * interleaving their migrations; the update script is the decision "deploy
 * revision X" being executed, with everything that can fail before the site
 * blinks failing before the site blinks. The script is asserted as text,
 * the way `bin/deploy.sh` always has been: what it must never contain is as
 * load-bearing as what it must.
 *
 * Knowingly unheld: the O_EXCL ('x') open inside acquire(). It only differs
 * from 'w' when two processes race through the same microseconds, which a
 * sequential test cannot arrange. It stays because the race is the point;
 * it is named here so a surviving mutant on that line is read as "the tests
 * cannot see this", not "this does nothing".
 */
final class UpdateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/lock-'.uniqid());
        mkdir($this->root.'/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/framework/deploy.lock');
        @rmdir($this->root.'/framework');
        @rmdir($this->root);

        parent::tearDown();
    }

    // --------------------------------------------------------------- lock

    #[Test]
    public function the_second_deploy_is_refused_while_the_first_holds_the_lock(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertNull($first->acquire());

        $age = $second->acquire();
        $this->assertNotNull($age);
        $this->assertLessThan(3600, $age);

        $first->release();
        $this->assertNull($second->acquire());
        $second->release();
    }

    #[Test]
    public function a_stale_lock_is_taken_over_instead_of_wedging_deploys_forever(): void
    {
        file_put_contents($this->root.'/framework/deploy.lock', (string) json_encode([
            'pid' => 12345,
            'acquired_at' => now()->subHours(2)->toAtomString(),
        ]));

        $this->assertNull($this->lock()->acquire());
    }

    #[Test]
    public function an_unreadable_lock_is_treated_as_stale_not_as_eternal(): void
    {
        // A crashed deploy can leave half a file. Refusing forever would turn
        // one crash into a permanently undeployable installation.
        file_put_contents($this->root.'/framework/deploy.lock', '{never finished');

        $this->assertNull($this->lock()->acquire());
    }

    #[Test]
    public function releasing_makes_room_and_the_file_is_gone(): void
    {
        $lock = $this->lock();
        $lock->acquire();
        $this->assertTrue($lock->isHeld());

        $lock->release();
        $this->assertFalse($lock->isHeld());
    }

    #[Test]
    public function the_deploy_command_refuses_while_another_holds_the_lock(): void
    {
        $this->app->bind(DeploymentLock::class, fn (): DeploymentLock => $this->lock());

        $holder = $this->lock();
        $this->assertNull($holder->acquire());

        $this->artisan('sanitube:deploy', ['--dry-run' => true])
            ->expectsOutputToContain('Another deploy holds the lock')
            ->assertFailed();

        $holder->release();
    }

    #[Test]
    public function the_deploy_command_releases_the_lock_even_when_a_stage_fails(): void
    {
        $lock = $this->lock();
        $this->app->bind(DeploymentLock::class, fn (): DeploymentLock => $lock);

        // An unset key fails preflight immediately; the lock must not stay.
        config(['app.key' => null]);

        $this->artisan('sanitube:deploy', ['--dry-run' => true])->assertFailed();

        $this->assertFalse($lock->isHeld());
    }

    // ------------------------------------------------------------- script

    #[Test]
    public function the_update_script_has_no_default_revision(): void
    {
        // "Whatever main is now" would deploy somebody else's afternoon. The
        // ref is a required argument, and its absence is a refusal.
        $script = $this->script();

        $this->assertStringContainsString('there is no default on purpose', $script);
        $this->assertStringNotContainsString('origin/main', $script);
        $this->assertStringNotContainsString('git pull', $script);
    }

    #[Test]
    public function the_update_script_refuses_a_dirty_tree(): void
    {
        // Local edits are somebody's uncommitted fix or an intrusion; the
        // update runs over neither.
        $script = $this->script();

        $this->assertStringContainsString('git status --porcelain', $script);
        $this->assertStringContainsString('will not run over them', $script);
    }

    #[Test]
    public function the_update_script_backs_up_before_the_site_blinks(): void
    {
        $script = $this->script();

        $backup = strpos($script, 'sanitube:backup');
        $down = strpos($script, 'artisan down');

        $this->assertNotFalse($backup);
        $this->assertNotFalse($down);
        $this->assertLessThan($down, $backup, 'The backup must come before maintenance mode.');
    }

    #[Test]
    public function the_update_script_brings_the_site_back_up_by_trap(): void
    {
        $this->assertMatchesRegularExpression("/trap 'php artisan up/", $this->script());
    }

    #[Test]
    public function the_update_script_is_never_destructive(): void
    {
        $script = $this->script();

        foreach (['migrate:fresh', 'migrate:reset', 'db:wipe', 'db:seed', '--seed', 'rm -rf'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, sprintf('[%s] has no business in an update.', $forbidden));
        }
    }

    #[Test]
    public function the_update_script_ties_the_frontend_to_the_target_revision(): void
    {
        $this->assertStringContainsString('sanitube:frontend:install "$FRONTEND" --sha="$TARGET"', $this->script());
    }

    #[Test]
    public function the_update_script_installs_without_development_dependencies(): void
    {
        $this->assertStringContainsString('--no-dev', $this->script());
        $this->assertStringNotContainsString('composer update', $this->script());
    }

    #[Test]
    public function the_update_script_is_executable(): void
    {
        $this->assertTrue(is_executable(base_path('bin/update.sh')), 'bin/update.sh is not executable.');
    }

    // ----------------------------------------------------------- fixtures

    private function lock(): DeploymentLock
    {
        return new DeploymentLock($this->root);
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('bin/update.sh'));
    }
}
