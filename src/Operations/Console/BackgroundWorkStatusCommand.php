<?php

declare(strict_types=1);

namespace SaniTube\Operations\Console;

use Illuminate\Console\Command;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Operations\Services\BacklogGuard;

/**
 * What the two gates currently say.
 *
 * Reports the queue depth as "unknown" rather than as zero when the driver
 * cannot answer. A number nobody measured is worse than an admission, because
 * it is the one an operator will act on.
 */
final class BackgroundWorkStatusCommand extends Command
{
    protected $signature = 'sanitube:work:status';

    protected $description = 'Report whether background work is paused and how deep the queue is.';

    public function handle(BackgroundWork $work, BacklogGuard $backlog): int
    {
        $state = $work->state();
        $depth = $backlog->depth();
        $ceiling = $backlog->ceiling();

        $this->line(sprintf('Paused:  %s', $state->is_paused ? 'yes ('.(string) $state->reason.')' : 'no'));
        $this->line(sprintf('Queue:   %s', $depth === null ? 'unknown (the driver does not report a size)' : (string) $depth));
        $this->line(sprintf('Ceiling: %s', $ceiling === 0 ? 'none' : (string) $ceiling));

        return self::SUCCESS;
    }
}
