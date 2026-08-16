<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Deployment\Services\DiagnoseProduction;

/**
 * Is this installation fit to be live?
 *
 * Read-only. It probes nothing it has to write to, starts no job and changes
 * no state — it is the command an operator runs on a server at the moment they
 * are least willing to have something else happen.
 *
 * Distinct from `sanitube:health`, which reports what the *machine* can do and
 * asks the same question in development as in production. This asks whether
 * the *installation* is configured the way a live one has to be, and most of
 * what it checks is correct on a laptop and dangerous on a public host.
 *
 * Exit code is the point: non-zero when something internal blocks going live,
 * so a deploy script can gate on it.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'sanitube:doctor {--json : Output the findings as JSON}';

    protected $description = 'Check whether this installation is fit to run in production';

    public function handle(DiagnoseProduction $diagnose): int
    {
        $checks = $diagnose->handle();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $this->blockers($checks) === [],
                'checks' => array_map(static fn (ProductionCheck $c): array => $c->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->blockers($checks) === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($checks);

        $blockers = $this->blockers($checks);

        if ($blockers === []) {
            $this->newLine();
            $this->info('Nothing internal blocks running this in production.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(sprintf('%d thing(s) must be fixed before this goes live:', count($blockers)));

        foreach ($blockers as $blocker) {
            $this->line(sprintf('  <fg=red>%s</> — %s', $blocker->key, $blocker->summary));

            if ($blocker->remediation !== null) {
                $this->line(sprintf('    %s', $blocker->remediation));
            }
        }

        return self::FAILURE;
    }

    /**
     * @param  list<ProductionCheck>  $checks
     */
    private function render(array $checks): void
    {
        $section = null;

        foreach ($checks as $check) {
            if ($check->section !== $section) {
                $section = $check->section;
                $this->newLine();
                $this->line(sprintf('<options=bold>%s</>', $section));
            }

            $this->line(sprintf('  %s %s', $this->marker($check->verdict), $check->summary));
        }
    }

    private function marker(string $verdict): string
    {
        return match ($verdict) {
            ProductionCheck::READY => '<fg=green>ok  </>',
            ProductionCheck::BLOCKER => '<fg=red>fail</>',
            ProductionCheck::WARNING => '<fg=yellow>warn</>',
            // Never rendered as a pass. An unanswered question is not a
            // healthy one, and a report that blurs the two reassures somebody
            // about a server that is already down.
            default => '<fg=yellow>  ? </>',
        };
    }

    /**
     * @param  list<ProductionCheck>  $checks
     * @return list<ProductionCheck>
     */
    private function blockers(array $checks): array
    {
        return array_values(array_filter($checks, static fn (ProductionCheck $c): bool => $c->isBlocking()));
    }
}
