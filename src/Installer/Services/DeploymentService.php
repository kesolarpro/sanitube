<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use SaniTube\Installer\StageResult;
use Throwable;

/**
 * Bringing a running installation up to a new version of the code.
 *
 * Split from {@see InstallationService} because installing and deploying are
 * different problems that only look alike. Installing runs once against an
 * empty machine and may create things. Deploying runs against a machine with a
 * live catalogue on it and **must create nothing**: no database, no owner, no
 * `.env`. If any of those are missing, the answer is that this is not a
 * deployment, and saying so is more useful than helpfully inventing them.
 *
 * The stages a *shell* has to run — `composer install`, `npm ci`, `npm run
 * build` — are deliberately not here. PHP cannot safely rewrite its own
 * autoloader mid-request, and a deploy command that tried would be running
 * half on the old code and half on the new. `bin/deploy.sh` does those and
 * then calls this.
 *
 * Everything is idempotent, and re-running after a failure resumes rather than
 * restarting. That is not a nicety: a deploy that fails at the cache stage on
 * shared hosting is going to be run again by somebody who cannot read a stack
 * trace, and the second run must not undo the first.
 */
final readonly class DeploymentService
{
    /**
     * Stages that must already be true, with why.
     *
     * A deployment is not an installation. Each of these is something
     * `sanitube:install` creates and a deploy must find already there — being
     * asked to deploy onto a machine with no database is a sign somebody is
     * running the wrong command, and creating one silently would turn that
     * mistake into a second, empty installation nobody notices.
     */
    public const REQUIRES = [
        'environment file' => 'A deployment updates an installation; it does not create one. Run sanitube:install first.',
        'application key' => 'Generating a key on a live installation would make every existing encrypted value and every signed URL unreadable.',
        'database' => 'A deployment migrates an existing schema. It never creates the database.',
    ];

    public function __construct(
        private Kernel $artisan,
        private Connection $connection,
    ) {}

    /**
     * Everything that must already be true before anything is changed.
     */
    public function preflight(): StageResult
    {
        if (! file_exists(base_path('.env'))) {
            return StageResult::failed('preflight', self::REQUIRES['environment file']);
        }

        if (trim((string) config('app.key')) === '') {
            return StageResult::failed('preflight', self::REQUIRES['application key']);
        }

        try {
            $this->connection->getPdo();
        } catch (Throwable $exception) {
            // The message is the driver's and it names a host and a user. That
            // is exactly the audience for it — somebody at a terminal on the
            // machine — and it is why this never travels to a browser.
            return StageResult::failed('preflight', self::REQUIRES['database'].' '.$exception->getMessage());
        }

        return StageResult::done('preflight', 'Environment, key and database are in place.');
    }

    /**
     * Whether anything is waiting to be applied.
     *
     * Reported before migrating rather than after, because "nothing to
     * migrate" and "migrated successfully" look identical in a log and mean
     * very different things when a deploy is being investigated.
     */
    public function pendingMigrations(): StageResult
    {
        try {
            $this->artisan->call('migrate:status', ['--pending' => true]);
            $output = trim($this->artisan->output());
        } catch (Throwable $exception) {
            return StageResult::failed('pending migrations', $exception->getMessage());
        }

        return $output === '' || str_contains($output, 'No pending migrations')
            ? StageResult::skipped('pending migrations', 'Nothing is waiting to be applied.')
            : StageResult::done('pending migrations', 'Migrations are waiting to be applied.');
    }

    /**
     * Apply the schema.
     *
     * `--force` because a deploy is non-interactive by definition, and
     * **never `--seed` and never `migrate:fresh`**. There is a live catalogue
     * on the other side of this call.
     */
    public function migrate(bool $dryRun = false): StageResult
    {
        if ($dryRun) {
            return StageResult::skipped('migrate', 'Dry run: no migration was applied.');
        }

        try {
            $this->artisan->call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            return StageResult::failed('migrate', $exception->getMessage());
        }

        return StageResult::done('migrate', 'The schema is up to date.');
    }

    /**
     * Rebuild the caches that make a production installation fast.
     *
     * Cleared and rebuilt rather than rebuilt over the top: a stale cached
     * config on a machine whose `.env` has just changed is the failure mode
     * where an operator edits a credential, restarts, and nothing happens.
     *
     * Views are compiled here rather than lazily, because the first person to
     * open a page after a deploy should not be the one who pays for it — and
     * on shared hosting that compilation can fail for permissions, which is a
     * thing to discover during a deploy and not during a demo.
     */
    public function rebuildCaches(bool $dryRun = false): StageResult
    {
        if ($dryRun) {
            return StageResult::skipped('caches', 'Dry run: no cache was rebuilt.');
        }

        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                $this->artisan->call($command);
            } catch (Throwable $exception) {
                return StageResult::failed('caches', $command.': '.$exception->getMessage());
            }
        }

        foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
            try {
                $this->artisan->call($command);
            } catch (Throwable $exception) {
                // Rebuilding failed after clearing, so the installation is now
                // uncached rather than wrongly cached. Slower, and correct —
                // which is the right way round to fail.
                return StageResult::failed(
                    'caches',
                    $command.' failed, so this installation is running uncached rather than '
                        .'from a stale cache: '.$exception->getMessage(),
                );
            }
        }

        return StageResult::done('caches', 'Configuration, routes and views are cached.');
    }

    /**
     * Point the public storage link at the disk.
     *
     * Skipped rather than failed when it already exists, and skipped rather
     * than failed when the platform will not allow a symlink at all — plenty
     * of shared hosts do not, and that is a deployment note rather than a
     * broken deploy.
     */
    public function storageLink(bool $dryRun = false): StageResult
    {
        $link = public_path('storage');

        if (file_exists($link) || is_link($link)) {
            return StageResult::skipped('storage link', 'The public storage link already exists.');
        }

        if ($dryRun) {
            return StageResult::skipped('storage link', 'Dry run: no link was created.');
        }

        try {
            $this->artisan->call('storage:link');
        } catch (Throwable $exception) {
            return StageResult::skipped(
                'storage link',
                'This host would not create a symlink. Serve public files another way: '
                    .$exception->getMessage(),
            );
        }

        return StageResult::done('storage link', 'The public storage link is in place.');
    }

    /**
     * Tell the workers to pick up the new code.
     *
     * A queue worker is a long-running PHP process that loaded the *old* code
     * when it started. Without this, a deploy leaves workers running the
     * previous version until somebody happens to restart them — and the
     * symptom is a job that behaves like last week's code, which is among the
     * hardest things there is to diagnose.
     *
     * Never a failure: an installation with no queue worker is a legitimate
     * one, and a restart signal nobody is listening for is a no-op.
     */
    public function restartWorkers(bool $dryRun = false): StageResult
    {
        if ($dryRun) {
            return StageResult::skipped('queue restart', 'Dry run: no restart was signalled.');
        }

        try {
            $this->artisan->call('queue:restart');
        } catch (Throwable $exception) {
            return StageResult::skipped(
                'queue restart',
                'Could not signal the workers. Restart them by hand: '.$exception->getMessage(),
            );
        }

        return StageResult::done(
            'queue restart',
            'Workers were signalled. They finish the job in hand before exiting, so a supervisor has to start them again.',
        );
    }
}
