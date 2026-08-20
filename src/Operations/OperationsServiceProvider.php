<?php

declare(strict_types=1);

namespace SaniTube\Operations;

use Illuminate\Support\ServiceProvider;
use SaniTube\Operations\Console\BackgroundWorkStatusCommand;
use SaniTube\Operations\Console\PauseBackgroundWorkCommand;
use SaniTube\Operations\Console\ResumeBackgroundWorkCommand;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PauseBackgroundWorkCommand::class,
                ResumeBackgroundWorkCommand::class,
                BackgroundWorkStatusCommand::class,
            ]);
        }
    }
}
