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

// Uploads that were started and never finished. Left alone they accumulate
// quietly, and on a shared account they accumulate until the quota stops
// something that matters. Nightly is often enough: the sweep only removes
// objects older than assets.staging.ttl_hours, so running it more often would
// find the same nothing.
Schedule::command('sanitube:assets:cleanup-staging')
    ->dailyAt('03:20')
    ->name('sanitube:cleanup-staging')
    ->withoutOverlapping();

// Re-reads stored assets and confirms their checksums. Not scheduled by
// default: on a large catalogue this is real egress and real money, and an
// installation should choose when to pay it. Uncomment, or run it by hand.
//
// Schedule::command('sanitube:assets:verify')
//     ->weeklyOn(1, '04:00')
//     ->name('sanitube:verify-assets')
//     ->withoutOverlapping();
