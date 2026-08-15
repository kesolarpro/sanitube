<?php

declare(strict_types=1);

namespace SaniTube\Assets\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Services\StagingJanitor;

/**
 * Removes uploads that were started and never finished.
 *
 * Intended for the scheduler, and therefore written to be dull: it only ever
 * touches the reserved staging prefix, it only deletes keys that parse as
 * something SaniTube wrote, it leaves anything an asset claims, and it names
 * every object it removes rather than reporting a count.
 *
 * `--hours` is the one way past the age floor, and it exists for a person at a
 * terminal who has decided to go below it. Nothing the scheduler runs can
 * reach it: `routes/console.php` passes no options, so a misconfigured
 * `SANITUBE_STAGING_TTL_HOURS=0` is clamped rather than obeyed.
 *
 * `--dry-run` exists because the first thing anyone sensibly does with a
 * command that deletes from storage is ask it what it would delete. The
 * deployment docs say to run it that way before enabling the cron.
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
        $dryRun = (bool) $this->option('dry-run');
        $requested = $this->requestedAgeSeconds();
        $belowFloor = $requested !== null && $requested < StagingJanitor::MINIMUM_AGE_SECONDS;

        if ($belowFloor && ! $dryRun) {
            $this->warn(sprintf(
                'Sweeping objects older than %d seconds, below the %d-second floor. An upload still in '
                    .'progress can be deleted by this. The scheduler cannot reach this path.',
                $requested,
                StagingJanitor::MINIMUM_AGE_SECONDS,
            ));
        }

        $report = $janitor->sweep(
            provider: is_string($provider = $this->option('provider')) && $provider !== '' ? $provider : null,
            olderThanSeconds: $requested,
            dryRun: $dryRun,
            allowAgeBelowFloor: $belowFloor,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf(
            '  %s%d abandoned upload(s) on [%s] older than %d seconds; %d left in place.',
            $dryRun ? '<fg=yellow>[dry run]</> ' : '',
            $report->count(),
            $report->provider,
            $report->ageSeconds,
            $report->kept(),
        ));

        foreach ($report->removed as $key) {
            $this->line(sprintf('    %s %s', $dryRun ? 'would remove' : 'removed', $key));
        }

        // Named with the reason rather than counted. An object that survives
        // every sweep forever is either a bug or an intrusion, and a bare
        // count says which one for neither.
        foreach ($report->skipped as $key => $reason) {
            $this->line(sprintf('    <fg=gray>kept</> %s — %s', $key, $reason));
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function requestedAgeSeconds(): ?int
    {
        $hours = $this->option('hours');

        return is_string($hours) && ctype_digit($hours) ? (int) $hours * 3600 : null;
    }
}
