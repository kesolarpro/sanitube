<?php

declare(strict_types=1);

namespace SaniTube\Assets\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Services\StagingJanitor;

/**
 * Removes uploads that were started and never finished.
 *
 * Intended for the scheduler, and therefore written to be dull: it only ever
 * touches the reserved staging prefix, it leaves anything an asset claims, and
 * it names every object it removes rather than reporting a count.
 *
 * `--dry-run` exists because the first thing anyone sensibly does with a
 * command that deletes from storage is ask it what it would delete.
 */
final class CleanUpStagingCommand extends Command
{
    protected $signature = 'sanitube:assets:cleanup-staging
                            {--provider= : Storage provider to sweep; defaults to the configured one}
                            {--hours= : Age threshold in hours; defaults to assets.staging.ttl_hours}
                            {--dry-run : List what would be removed without removing anything}
                            {--json : Output the outcome as JSON}';

    protected $description = 'Delete abandoned staging uploads, leaving every asset of record untouched';

    public function handle(StagingJanitor $janitor): int
    {
        $hours = $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        $report = $janitor->sweep(
            provider: is_string($provider = $this->option('provider')) && $provider !== '' ? $provider : null,
            olderThanSeconds: is_string($hours) && ctype_digit($hours) ? (int) $hours * 3600 : null,
            dryRun: $dryRun,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf(
            '  %s%d abandoned upload(s) on [%s]; %d left in place.',
            $dryRun ? '<fg=yellow>[dry run]</> ' : '',
            $report->count(),
            $report->provider,
            $report->kept,
        ));

        foreach ($report->removed as $key) {
            $this->line(sprintf('    %s %s', $dryRun ? 'would remove' : 'removed', $key));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
