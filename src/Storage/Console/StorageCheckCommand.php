<?php

declare(strict_types=1);

namespace SaniTube\Storage\Console;

use Illuminate\Console\Command;
use SaniTube\Storage\Certification\CertificationCheck;
use SaniTube\Storage\Certification\CertificationOutcome;
use SaniTube\Storage\Certification\CertificationReport;
use SaniTube\Storage\Certification\CertifyStorage;
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
 * support threads. `--certify` prints no signed URL either: the signature is
 * the permission, and a report is read later and by more people than the
 * operator who ran it.
 *
 * **`--certify` asks a harder question.** The default probe answers "can this
 * install store a master?". Certification answers "can it do the things
 * production actually asks of it?" — promote an object server-side, hand a
 * browser a signed URL the service will accept, take a presigned upload. Each
 * of those fails on its own, for its own reason, and none of them is visible
 * to a write/read/delete probe. It is slower and it makes real requests, which
 * is why it is a flag and not the default.
 */
final class StorageCheckCommand extends Command
{
    protected $signature = 'sanitube:storage:check
                            {provider?* : Providers to check; defaults to every configured one}
                            {--certify : Also prove move, signed reads and presigned uploads against the real service}
                            {--json : Output the report as JSON}';

    protected $description = 'Verify that configured storage providers can be written, read and deleted';

    public function handle(StorageManager $storage, CertifyStorage $certifier): int
    {
        /** @var list<string> $requested */
        $requested = (array) $this->argument('provider');
        $names = $requested === [] ? $storage->names() : $requested;

        if ($this->option('certify')) {
            return $this->certify($storage, $certifier, $names);
        }

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

    /**
     * @param  list<string>  $names
     */
    private function certify(StorageManager $storage, CertifyStorage $certifier, array $names): int
    {
        $reports = [];

        foreach ($names as $name) {
            try {
                $reports[] = $certifier->certify($storage->provider($name));
            } catch (Throwable $exception) {
                // A configured name that cannot be resolved is itself a
                // result, and exactly the failure this command exists to find.
                $reports[] = new CertificationReport(
                    $name,
                    [CertificationCheck::failed('resolve', $exception->getMessage())],
                );
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (CertificationReport $report): array => $report->toArray(), $reports),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->renderCertification($reports);
        }

        foreach ($reports as $report) {
            if (! $report->certified()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<CertificationReport>  $reports
     */
    private function renderCertification(array $reports): void
    {
        $rows = [];

        foreach ($reports as $report) {
            foreach ($report->checks as $check) {
                $rows[] = [
                    $report->provider,
                    $check->name,
                    match ($check->outcome) {
                        CertificationOutcome::Passed => '<fg=green>passed</>',
                        CertificationOutcome::Failed => '<fg=red>failed</>',
                        CertificationOutcome::Skipped => '<fg=yellow>skipped</>',
                    },
                    $check->detail ?? '',
                ];
            }
        }

        $this->newLine();
        $this->table(['Provider', 'Check', 'Outcome', 'Detail'], $rows);

        foreach ($reports as $report) {
            $this->line(sprintf(
                '  %s: %s',
                $report->provider,
                $report->certified() ? '<fg=green>certified</>' : '<fg=red>not certified</>',
            ));
        }

        // Said once, plainly, because a green table invites the opposite
        // conclusion: a browser refuses a cross-origin PUT before the request
        // is made, so nothing reaches the service to be observed. No command
        // can prove the bucket's CORS rule. Only an upload from the interface
        // can.
        $this->newLine();
        $this->line('  <fg=yellow>Not covered:</> the bucket\'s CORS rule. A browser refuses a cross-origin');
        $this->line('  PUT before it is sent, so no command can see it. Upload one file from the');
        $this->line('  interface to finish certifying a direct-upload install.');
        $this->newLine();
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
