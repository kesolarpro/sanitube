<?php

declare(strict_types=1);

namespace SaniTube\Worker\Console;

use Illuminate\Console\Command;
use SaniTube\Worker\Certification\CertifyWorker;
use SaniTube\Worker\Certification\WorkerCheck;
use SaniTube\Worker\Certification\WorkerCheckOutcome;

/**
 * Prove a real worker is what this installation thinks it is.
 *
 * The counterpart to `sanitube:storage:check --certify`, and it exists for the
 * same reason: the handshake, the refusals and the containment are tested
 * against a faked transport, which is the right way to test them and says
 * nothing about the machine at the end of a URL somebody typed into `.env`.
 *
 * **Nothing here prints the token.** Not the value, not a prefix, not from an
 * exception. The worker is named by the name it announces, never by its
 * address: this output is what somebody pastes into a support thread, and the
 * URL is the one thing in it that says where a private machine lives.
 */
final class WorkerCheckCommand extends Command
{
    protected $signature = 'sanitube:worker:check {--json : Output the findings as JSON}';

    protected $description = 'Certify the configured worker: handshake, capabilities, refusals and token.';

    public function handle(CertifyWorker $certifier): int
    {
        $checks = $certifier->handle();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['checks' => array_map(static fn (WorkerCheck $check): array => $check->toArray(), $checks)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $this->exitCode($checks);
        }

        $this->table(
            ['Check', 'Outcome', 'Detail'],
            array_map(static fn (WorkerCheck $check): array => [
                $check->name,
                match ($check->outcome) {
                    WorkerCheckOutcome::Passed => '<fg=green>passed</>',
                    WorkerCheckOutcome::Failed => '<fg=red>failed</>',
                    WorkerCheckOutcome::Skipped => '<fg=yellow>skipped</>',
                },
                $check->detail ?? '',
            ], $checks),
        );

        $this->newLine();
        $this->line('  <fg=yellow>Not covered:</> a real job. This proves the protocol — who the worker is,');
        $this->line('  what it says it can do, that it refuses what it does not do, and that its token');
        $this->line('  is enforced. It never asks it to process a master, so nothing here says the');
        $this->line('  worker can reach your storage or finish real work. Run one import to see that.');

        return $this->exitCode($checks);
    }

    /**
     * @param  list<WorkerCheck>  $checks
     */
    private function exitCode(array $checks): int
    {
        foreach ($checks as $check) {
            if ($check->outcome === WorkerCheckOutcome::Failed) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
