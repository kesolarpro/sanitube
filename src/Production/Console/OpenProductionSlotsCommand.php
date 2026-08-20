<?php

declare(strict_types=1);

namespace SaniTube\Production\Console;

use Illuminate\Console\Command;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Production\Services\OpenProductionSlots;

/**
 * Opens the occasions a plan's cadence is due for.
 *
 * **It opens rows and nothing else.** No generation, no provider call, no
 * money. Running it twice in a minute is safe and running it every hour is
 * safe, which is what makes it a sane thing to put in a cron entry an operator
 * never looks at again.
 *
 * It asks the global stop first. An operator who paused the platform meant it,
 * and a scheduler that kept opening work during a pause would hand the queue a
 * backlog the moment they resumed — which is the opposite of what pausing is
 * for.
 *
 * The backlog ceiling is deliberately **not** asked. That guard is about how
 * many jobs are queued, and this enqueues none: a slot is a row waiting for
 * somebody, and refusing to write it because the queue is busy would lose the
 * occasion rather than defer it.
 */
final class OpenProductionSlotsCommand extends Command
{
    protected $signature = 'sanitube:production:open-slots
        {--dry-run : report what would be opened and write nothing}';

    protected $description = 'Open the production slots that active plans are due for.';

    public function handle(OpenProductionSlots $scheduler, BackgroundWork $work): int
    {
        if ($work->isPaused()) {
            $this->error('Refused: BACKGROUND_WORK_PAUSED');
            $this->line('Nothing was opened. Resume the platform to let plans continue.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            // Honest about what a dry run can and cannot tell you. Reporting a
            // number here would mean running the scheduler and rolling back,
            // and a rollback that raced another process would report a slot
            // that then really existed.
            $this->info('Nothing was opened.');
            $this->line('A dry run reports no count: opening a slot is the only way to know whether one was due, and this command deliberately does not do that to find out.');

            return self::SUCCESS;
        }

        $opened = $scheduler->handle();

        $this->info(sprintf('Opened %d slot(s).', count($opened)));

        // Said plainly, because "opened 3 slots" reads as "made three tracks"
        // to anybody who has not read this class.
        $this->line('A slot is an occasion waiting for something to claim it. Nothing has been generated and nothing has been spent.');

        return self::SUCCESS;
    }
}
