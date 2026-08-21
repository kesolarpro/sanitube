<?php

declare(strict_types=1);

namespace SaniTube\Worker\Console;

use Illuminate\Console\Command;

/**
 * Mint a worker token — once, to the terminal, and nowhere else.
 *
 * The token is the whole authorisation between Core and its worker, so its
 * handling is deliberately spartan: this command generates one and prints
 * it, and that is all. It writes no file, edits no `.env`, remembers
 * nothing — the operator puts the value in both environments, and from that
 * moment the platform's rule holds again: the token is never logged, never
 * echoed back, never carried in a message.
 *
 * Printing a secret to a terminal is the one accepted exposure, the same
 * one every password manager's "show" button makes — the operator asked,
 * the terminal is theirs, and the alternative is the token transiting
 * something with a history.
 */
final class WorkerTokenCommand extends Command
{
    protected $signature = 'sanitube:worker:token';

    protected $description = 'Generate a worker token to set on both Core and the worker host';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));

        $this->line('Set this on BOTH hosts, then never write it anywhere else:');
        $this->newLine();
        $this->line('  SANITUBE_WORKER_TOKEN='.$token);
        $this->newLine();
        $this->line('Core additionally needs SANITUBE_WORKER_URL pointing at the worker.');
        $this->line('On the worker host, the token is what makes its routes exist at all.');
        $this->line('Verify the pair with: php artisan sanitube:worker:check');

        return self::SUCCESS;
    }
}
