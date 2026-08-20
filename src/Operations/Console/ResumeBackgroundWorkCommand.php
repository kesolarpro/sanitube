<?php

declare(strict_types=1);

namespace SaniTube\Operations\Console;

use Illuminate\Console\Command;
use SaniTube\Operations\Services\BackgroundWork;

final class ResumeBackgroundWorkCommand extends Command
{
    protected $signature = 'sanitube:work:resume';

    protected $description = 'Start taking on background work again.';

    public function handle(BackgroundWork $work): int
    {
        $work->resume(null);

        $this->info('Background work has resumed.');

        return self::SUCCESS;
    }
}
