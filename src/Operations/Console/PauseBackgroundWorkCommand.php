<?php

declare(strict_types=1);

namespace SaniTube\Operations\Console;

use Illuminate\Console\Command;
use SaniTube\Operations\Services\BackgroundWork;

/**
 * Stop this installation taking on background work.
 *
 * A console command as well as an interface control, because the moment an
 * operator most needs this is the moment the interface is the thing that is
 * struggling.
 */
final class PauseBackgroundWorkCommand extends Command
{
    protected $signature = 'sanitube:work:pause {--reason=OPERATOR_REQUEST : a short machine-readable code}';

    protected $description = 'Stop taking on new background work.';

    public function handle(BackgroundWork $work): int
    {
        $reason = (string) $this->option('reason');
        $state = $work->pause(null, $reason === '' ? 'OPERATOR_REQUEST' : $reason);

        // Said plainly, because the thing this does not do is the thing an
        // operator will assume it does.
        $this->info(sprintf('Background work is paused (%s).', (string) $state->reason));
        $this->line('Jobs already running will finish. Nothing new will be taken on.');

        return self::SUCCESS;
    }
}
