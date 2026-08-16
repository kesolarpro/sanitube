<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SaniTube\Installer\Services\DeploymentService;
use SaniTube\Installer\StageResult;
use Tests\TestCase;

/**
 * Bringing a running installation up to new code.
 *
 * A deploy is not an install, and almost every test here is about that
 * difference. An install runs once against an empty machine and may create
 * things. A deploy runs against a machine with a live catalogue on it and
 * **creates nothing** — no database, no key, no `.env`. If any of those are
 * missing, this is not a deployment, and saying so is more useful than
 * helpfully inventing them: a silently generated key makes every existing
 * encrypted value and signed URL unreadable, and a silently created database
 * is a second, empty installation nobody notices.
 *
 * The other claim is that **nothing here is destructive.** No `migrate:fresh`,
 * no seeding, no clearing of anything that is not a cache. A deploy that
 * failed halfway is re-run, not undone.
 */
final class DeploymentTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------- preflight

    #[Test]
    public function a_deployable_installation_passes_preflight(): void
    {
        $result = $this->deployment()->preflight();

        $this->assertFalse($result->hasFailed());
        $this->assertSame(StageResult::DONE, $result->outcome);
    }

    #[Test]
    public function deploying_without_an_application_key_is_refused(): void
    {
        config(['app.key' => '']);

        $result = $this->deployment()->preflight();

        // Generating one here would make every existing encrypted value and
        // every signed URL on the installation unreadable. Refusing is the
        // only safe answer.
        $this->assertTrue($result->hasFailed());
        $this->assertStringContainsString('unreadable', $result->detail);
    }

    #[Test]
    public function the_preflight_requirements_say_why_rather_than_only_what(): void
    {
        // Each requirement carries its reason, because an operator on shared
        // hosting who reads "database: missing" learns nothing they can act
        // on, and the reason is what tells them they ran the wrong command.
        foreach (DeploymentService::REQUIRES as $requirement => $why) {
            $this->assertNotSame('', $why, $requirement.' has no stated reason.');
            $this->assertGreaterThan(40, strlen($why), $requirement.'’s reason is a label, not a reason.');
        }
    }

    // ------------------------------------------------------------- migrations

    #[Test]
    public function an_installation_with_nothing_pending_says_so_rather_than_reporting_success(): void
    {
        // RefreshDatabase has already migrated. "Nothing to apply" and
        // "applied successfully" look identical in a log and mean very
        // different things when a deploy is being investigated.
        $result = $this->deployment()->pendingMigrations();

        $this->assertFalse($result->hasFailed());
        $this->assertSame(StageResult::SKIPPED, $result->outcome);
    }

    #[Test]
    public function a_dry_run_applies_no_migration(): void
    {
        $result = $this->deployment()->migrate(dryRun: true);

        $this->assertSame(StageResult::SKIPPED, $result->outcome);
        $this->assertStringContainsString('Dry run', $result->detail);
    }

    #[Test]
    public function migrating_an_up_to_date_schema_is_a_no_op_rather_than_an_error(): void
    {
        // Idempotence is the property that makes a failed deploy re-runnable
        // by somebody who cannot read a stack trace.
        $this->assertFalse($this->deployment()->migrate()->hasFailed());
        $this->assertFalse($this->deployment()->migrate()->hasFailed());
    }

    // ----------------------------------------------------------------- caches

    #[Test]
    public function a_dry_run_rebuilds_no_cache(): void
    {
        $result = $this->deployment()->rebuildCaches(dryRun: true);

        $this->assertSame(StageResult::SKIPPED, $result->outcome);
    }

    // ----------------------------------------------------- link and workers

    #[Test]
    public function an_existing_storage_link_is_left_alone(): void
    {
        // Whether the link exists in this checkout or not, the stage must
        // never fail: a host that forbids symlinks is a legitimate host, and
        // an existing link is not a problem to solve.
        $result = $this->deployment()->storageLink(dryRun: true);

        $this->assertFalse($result->hasFailed());
    }

    #[Test]
    public function signalling_the_workers_never_fails_a_deploy(): void
    {
        // An installation with no queue worker is legitimate, and a restart
        // signal nobody is listening for is a no-op. Failing a release over
        // it would teach people to ignore the exit code.
        $this->assertFalse($this->deployment()->restartWorkers()->hasFailed());
    }

    #[Test]
    public function a_worker_restart_that_throws_is_still_not_a_failed_deploy(): void
    {
        // Asserting the happy path does not exercise the catch. A mutation
        // that turned this branch into a failure survived a suite that only
        // ever ran it against a working queue.
        $result = $this->deploymentThatCannotRunCommands()->restartWorkers();

        $this->assertSame(StageResult::SKIPPED, $result->outcome);
        $this->assertStringContainsString('by hand', $result->detail);
    }

    #[Test]
    public function a_host_that_forbids_symlinks_is_a_note_and_not_a_failed_deploy(): void
    {
        // Plenty of shared hosts refuse symlinks outright. That is a
        // deployment note — serve public files another way — not a reason to
        // fail a release.
        $link = public_path('storage');
        $existed = is_link($link);
        $target = $existed ? (string) readlink($link) : null;

        if ($existed) {
            // The stage short-circuits on an existing link, so the failure
            // branch is only reachable without one.
            unlink($link);
        }

        try {
            $result = $this->deploymentThatCannotRunCommands()->storageLink();

            $this->assertSame(StageResult::SKIPPED, $result->outcome);
            $this->assertStringContainsString('another way', $result->detail);
        } finally {
            if ($target !== null) {
                symlink($target, $link);
            }
        }
    }

    // -------------------------------------------------------------- the command

    #[Test]
    public function a_dry_run_reports_that_nothing_was_changed(): void
    {
        $this->artisan('sanitube:deploy', ['--dry-run' => true])
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();
    }

    #[Test]
    public function a_deploy_runs_every_stage_and_names_each_one(): void
    {
        $this->artisan('sanitube:deploy')
            ->expectsOutputToContain('preflight')
            ->expectsOutputToContain('caches')
            ->expectsOutputToContain('storage link')
            ->expectsOutputToContain('queue restart')
            ->expectsOutputToContain('SaniTube is deployed')
            ->assertSuccessful();
    }

    #[Test]
    public function a_deploy_stops_at_the_first_stage_that_fails(): void
    {
        config(['app.key' => '']);

        // Nothing after preflight may run. A deploy that carried on past a
        // failed precondition would cache a broken configuration over a
        // working one.
        $this->artisan('sanitube:deploy')
            ->doesntExpectOutputToContain('SaniTube is deployed')
            ->assertFailed();
    }

    #[Test]
    public function migrations_can_be_left_out_explicitly_and_says_that_it_was_asked_for(): void
    {
        $this->artisan('sanitube:deploy', ['--skip-migrations' => true])
            ->expectsOutputToContain('--skip-migrations')
            ->assertSuccessful();
    }

    // ------------------------------------------------------- the shell script

    #[Test]
    public function the_shell_script_never_decides_which_revision_to_deploy(): void
    {
        $script = (string) file_get_contents(base_path('bin/deploy.sh'));

        // Which revision is deployed belongs to the deployer. A script that
        // pulls decides for them, and deploys the wrong branch one day.
        $this->assertDoesNotMatchRegularExpression('/^\s*git\s+(pull|fetch|checkout)/m', $script);
    }

    #[Test]
    public function the_shell_script_is_never_destructive(): void
    {
        $script = (string) file_get_contents(base_path('bin/deploy.sh'));

        // There is a live catalogue on the other side of this script.
        foreach (['migrate:fresh', 'migrate:reset', 'db:wipe', '--seed', 'db:seed'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $script,
                $forbidden.' has no place in a deployment script.',
            );
        }
    }

    #[Test]
    public function the_shell_script_installs_without_development_dependencies(): void
    {
        $script = (string) file_get_contents(base_path('bin/deploy.sh'));

        // Asserted against the command line, not against the file. The first
        // version of this test looked for the flags anywhere in the script and
        // was satisfied by the *comment* explaining them — so removing them
        // from the command changed nothing and the mutation survived.
        $this->assertMatchesRegularExpression(
            '/^composer install .*--no-dev/m',
            $script,
            'The composer install line does not pass --no-dev.',
        );
        $this->assertMatchesRegularExpression(
            '/^composer install .*--optimize-autoloader/m',
            $script,
            'The composer install line does not pass --optimize-autoloader.',
        );
    }

    #[Test]
    public function the_shell_script_brings_the_site_back_up_even_when_a_step_fails(): void
    {
        $script = (string) file_get_contents(base_path('bin/deploy.sh'));

        // Without the trap, any failure below `artisan down` leaves the
        // installation dark until somebody notices.
        $this->assertStringContainsString('artisan down', $script);
        $this->assertMatchesRegularExpression('/trap .*artisan up/', $script);
    }

    #[Test]
    public function the_shell_script_stops_on_the_first_error(): void
    {
        $script = (string) file_get_contents(base_path('bin/deploy.sh'));

        // Without `set -e`, a failed composer install is followed cheerfully
        // by a cache rebuild against half-installed code.
        $this->assertMatchesRegularExpression('/^set -euo pipefail$/m', $script);
    }

    #[Test]
    public function the_shell_script_is_executable(): void
    {
        $this->assertTrue(is_executable(base_path('bin/deploy.sh')));
    }

    private function deployment(): DeploymentService
    {
        return $this->app->make(DeploymentService::class);
    }

    /**
     * A deployment whose every artisan call throws.
     *
     * Stands in for the two hosts this module has to survive: one with no
     * queue worker and one that forbids symlinks. Both are legitimate
     * installations, and neither may fail a release.
     */
    private function deploymentThatCannotRunCommands(): DeploymentService
    {
        $kernel = new class implements Kernel
        {
            public function bootstrap(): void {}

            public function handle($input, $output = null): int
            {
                return 1;
            }

            /** @param  array<string, mixed>  $parameters */
            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                throw new RuntimeException('This host will not run that.');
            }

            /** @param  array<string, mixed>  $parameters */
            public function queue($command, array $parameters = []): PendingDispatch
            {
                throw new RuntimeException('This host will not run that.');
            }

            /** @return array<string, mixed> */
            public function all(): array
            {
                return [];
            }

            public function output(): string
            {
                return '';
            }

            public function terminate($input, $status): void {}
        };

        /** @var Connection $connection */
        $connection = $this->app->make('db')->connection();

        return new DeploymentService($kernel, $connection);
    }
}
