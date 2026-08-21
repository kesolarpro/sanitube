<?php

declare(strict_types=1);

namespace SaniTube\Assets\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Services\RelocateAssets;
use Throwable;

/**
 * The 4,000-file path: migrate the catalogue's bytes to another provider in
 * operator-sized batches, from a shell, with nothing to keep open.
 *
 * Run it, read the report, run it again: done is detected, not remembered,
 * so stopping at any point — ctrl-c included — loses nothing but the file
 * in flight, whose unverified copy cleans itself up. The kill switch is the
 * absence of the next invocation.
 */
final class RelocateAssetsCommand extends Command
{
    protected $signature = 'sanitube:assets:relocate
                            {target : The storage provider to move assets to (see config/storage.php)}
                            {--limit=50 : Assets per run — backpressure is choosing this number}
                            {--dry-run : Count and name what would move, moving nothing}
                            {--json : Output the outcome as JSON}';

    protected $description = 'Copy assets to another storage provider, verify each against its recorded checksum, then switch — never deleting a source';

    public function handle(RelocateAssets $relocation): int
    {
        $target = (string) $this->argument('target');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = $this->option('dry-run') === true;

        try {
            $report = $relocation->toProvider($target, $limit, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'dry_run' => $dryRun,
                'moved' => $report['moved'],
                'already_on_target' => $report['already'],
                'failed' => $report['failed'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $verb = $dryRun ? 'would move' : 'moved';
            $this->line(sprintf('%s %d, already on [%s]: %d, failed: %d', $verb, count($report['moved']), $target, $report['already'], count($report['failed'])));

            foreach ($report['failed'] as $uuid => $reason) {
                $this->line(sprintf('  <fg=red>failed</> %s %s', $uuid, $reason));
            }

            if (! $dryRun && $report['moved'] !== []) {
                $this->line('Sources were not deleted, deliberately: retiring local copies after a verified migration is a separate, explicit decision.');
            }
        }

        return $report['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
