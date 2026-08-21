<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;

/**
 * Every non-destructive check this platform has, in one sitting.
 *
 * Deliberately a conductor and nothing else: the machine's capabilities are
 * `sanitube:health`'s question, fitness to be live is the doctor's, provider
 * standings are `sanitube:providers`', and real HTTP is the smoke test's. A
 * self-test that re-implemented any of them would be the copy that
 * eventually disagrees — so this one only decides the order, skips the
 * smoke half when there is no URL to aim at, and fails if any part failed.
 */
final class SelfTestCommand extends Command
{
    protected $signature = 'sanitube:self-test';

    protected $description = 'Run every non-destructive check: capabilities, doctor, providers, and HTTP smoke when possible';

    public function handle(): int
    {
        $failed = false;

        $this->line('<info>== What this machine can do</info>');
        $this->call('sanitube:health');

        $this->newLine();
        $this->line('<info>== Fit to be live?</info>');

        if ($this->call('sanitube:doctor') !== self::SUCCESS) {
            $failed = true;
        }

        $this->newLine();
        $this->line('<info>== Provider standings</info>');
        $this->call('sanitube:providers');

        $url = (string) config('app.url');

        if (str_starts_with($url, 'http')) {
            $this->newLine();
            $this->line('<info>== Real HTTP</info>');

            if ($this->call('sanitube:smoke') !== self::SUCCESS) {
                $failed = true;
            }
        } else {
            $this->newLine();
            $this->line('No usable APP_URL, so no HTTP smoke — set it and re-run for the full picture.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
