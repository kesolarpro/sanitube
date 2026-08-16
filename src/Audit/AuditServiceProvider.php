<?php

declare(strict_types=1);

namespace SaniTube\Audit;

use Illuminate\Support\ServiceProvider;
use SaniTube\Audit\Console\PruneAuditLogCommand;
use SaniTube\Audit\Support\Redaction;

/**
 * The audit module.
 *
 * `Redaction` is a singleton because it is stateless and every write goes
 * through it; `RecordAuditEvent` deliberately is *not*, because it resolves the
 * request it is writing about and a singleton would answer for whichever
 * request happened to build it first.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Redaction::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAuditLogCommand::class]);
        }
    }
}
