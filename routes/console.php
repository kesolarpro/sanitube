<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use SaniTube\Observability\SchedulerHeartbeat;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| One cron entry drives everything:
|
|   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
|
| That single line works identically on cPanel, a VPS and a dedicated
| server, which is why no task here may assume Supervisor, systemd or Redis.
|
*/

// Proves the cron entry exists and is still firing. Without it, a missing
// crontab is invisible until royalties stop importing weeks later.
Schedule::call(function (SchedulerHeartbeat $heartbeat): void {
    $heartbeat->record();
})->everyMinute()->name('sanitube:scheduler-heartbeat')->withoutOverlapping();
