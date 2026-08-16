<?php

declare(strict_types=1);

namespace SaniTube\Installer\Console;

use Illuminate\Console\Command;
use SaniTube\Installer\Services\DeploymentService;
use SaniTube\Installer\StageResult;

/**
 * `php artisan sanitube:deploy` — the PHP half of a deployment.
 *
 * The shell half — `composer install`, `npm ci`, `npm run build` — is
 * `bin/deploy.sh`, and the split is not tidiness. PHP cannot safely rewrite
 * its own autoloader mid-request: a command that ran `composer install` on
 * itself would finish the run half on the old code and half on the new.
 *
 * Non-interactive by construction. A deploy runs from a hook, a cron entry or
 * somebody's terminal at three in the morning, and a command that stops to ask
 * a question is a command that hangs a release.
 *
 * `--dry-run` reports what each stage *would* do and changes nothing. It is
 * the way to find out whether a machine is deployable before finding out the
 * hard way, and it is the one thing worth running on a host nobody has
 * deployed to before.
 */
final class DeployCommand extends Command
{
    protected $signature = 'sanitube:deploy
                            {--dry-run : Report what would happen and change nothing}
                            {--skip-migrations : Rebuild caches and restart workers without touching the schema}';

    protected $description = 'Bring a running installation up to the deployed code: schema, caches, storage link, workers';

    public function handle(DeploymentService $deployment): int
    {
        $dryRun = $this->option('dry-run') === true;

        $this->line($dryRun ? 'SaniTube deployment (dry run)' : 'SaniTube deployment');
        $this->newLine();

        // Preflight is not one of the stages that can be skipped. Everything
        // after it assumes an installation exists, and inventing the missing
        // pieces would turn "you ran the wrong command" into a second, empty
        // installation nobody notices.
        if (! $this->report($deployment->preflight())) {
            return self::FAILURE;
        }

        if ($this->option('skip-migrations') === true) {
            $this->reportLine(StageResult::skipped('migrate', 'Asked for explicitly with --skip-migrations.'));
        } else {
            if (! $this->report($deployment->pendingMigrations())) {
                return self::FAILURE;
            }

            if (! $this->report($deployment->migrate($dryRun))) {
                return self::FAILURE;
            }
        }

        // Caches come after migrations on purpose. A cached config rebuilt
        // before a migration that adds a setting is a cache that has to be
        // rebuilt again, and nobody does it twice.
        if (! $this->report($deployment->rebuildCaches($dryRun))) {
            return self::FAILURE;
        }

        // Neither of these can fail a deploy. A host that forbids symlinks and
        // an installation with no queue worker are both legitimate, and
        // failing a release over them would teach people to ignore the exit
        // code.
        $this->reportLine($deployment->storageLink($dryRun));
        $this->reportLine($deployment->restartWorkers($dryRun));

        $this->newLine();

        if ($dryRun) {
            $this->info('Nothing was changed. This installation looks deployable.');

            return self::SUCCESS;
        }

        $this->info('SaniTube is deployed.');
        $this->line('If a supervisor manages the queue workers, they will come back on their own. If not, start them again.');

        return self::SUCCESS;
    }

    private function report(StageResult $result): bool
    {
        $this->reportLine($result);

        return ! $result->hasFailed();
    }

    private function reportLine(StageResult $result): void
    {
        $label = match ($result->outcome) {
            StageResult::DONE => '<info>done</info>',
            StageResult::SKIPPED => '<comment>skipped</comment>',
            default => '<error>failed</error>',
        };

        $this->line(sprintf('  %-22s %s', $result->stage, $label));

        if ($result->detail !== '') {
            $this->line('    '.$result->detail);
        }
    }
}
