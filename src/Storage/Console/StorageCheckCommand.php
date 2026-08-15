<?php

declare(strict_types=1);

namespace SaniTube\Storage\Console;

use Illuminate\Console\Command;
use SaniTube\Storage\StorageHealth;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * Answers "can this install actually store a master?" before someone finds out
 * the hard way.
 *
 * Every check is a real round trip — write, read back, delete — because
 * configuration that parses is not configuration that works. Credentials with
 * PutObject and no DeleteObject look perfect until the first staging cleanup.
 *
 * Nothing here prints a credential. Provider names, bucket-free keys and
 * redacted messages only; the output of this command is what gets pasted into
 * support threads.
 */
final class StorageCheckCommand extends Command
{
    protected $signature = 'sanitube:storage:check
                            {provider?* : Providers to check; defaults to every configured one}
                            {--json : Output the report as JSON}';

    protected $description = 'Verify that configured storage providers can be written, read and deleted';

    public function handle(StorageManager $storage): int
    {
        /** @var list<string> $requested */
        $requested = (array) $this->argument('provider');
        $names = $requested === [] ? $storage->names() : $requested;

        $reports = [];

        foreach ($names as $name) {
            $reports[] = $this->probe($storage, $name);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (StorageHealth $health): array => $health->toArray(), $reports),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->render($reports);
        }

        foreach ($reports as $report) {
            if (! $report->healthy) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function probe(StorageManager $storage, string $name): StorageHealth
    {
        try {
            return $storage->provider($name)->healthCheck();
        } catch (Throwable $exception) {
            // An unknown or unconstructable provider is itself a result: a
            // configured name that cannot be resolved is exactly the failure
            // this command exists to surface.
            return StorageHealth::failing(
                $name,
                ['resolve' => false],
                false,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param  list<StorageHealth>  $reports
     */
    private function render(array $reports): void
    {
        $rows = [];

        foreach ($reports as $report) {
            $failed = $report->failedChecks();

            $rows[] = [
                $report->provider,
                $report->healthy ? '<fg=green>ok</>' : '<fg=red>failed</>',
                $report->temporaryUrls ? 'yes' : '<fg=yellow>no</>',
                $failed === [] ? '' : implode(', ', $failed),
            ];
        }

        $this->newLine();
        $this->table(['Provider', 'Status', 'Signed URLs', 'Failed checks'], $rows);

        foreach ($reports as $report) {
            if ($report->detail === null) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('  <options=bold>%s</>', $report->provider));
            $this->line('  '.$report->detail);
        }

        foreach ($reports as $report) {
            if ($report->healthy && ! $report->temporaryUrls) {
                $this->newLine();
                $this->line(sprintf(
                    '  <fg=yellow>%s works but cannot sign URLs.</> Audio will be served through the '
                        .'application instead, which is slower and keeps every byte on this server.',
                    $report->provider,
                ));
            }
        }

        $this->newLine();
    }
}
