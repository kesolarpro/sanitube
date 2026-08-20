<?php

declare(strict_types=1);

namespace SaniTube\Production;

use Illuminate\Support\ServiceProvider;
use SaniTube\Production\Console\OpenProductionSlotsCommand;

/**
 * Wires the production module.
 *
 * One command, and no listener. A plan is a standing intention and a slot is an
 * occasion; nothing here listens for an event, because opening occasions is
 * something a clock does rather than something an event causes.
 *
 * **No scheduled task is registered either**, deliberately. This platform's
 * baseline is a shared account whose cron entry an operator writes once, and a
 * framework schedule that only runs when `schedule:run` does would be a second
 * place for the same decision to live -- silently doing nothing on exactly the
 * installations that most need it to be visible. The command is the interface;
 * where it is called from is the operator's.
 */
final class ProductionServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([OpenProductionSlotsCommand::class]);
        }
    }
}
