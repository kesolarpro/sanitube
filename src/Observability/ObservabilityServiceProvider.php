<?php

declare(strict_types=1);

namespace SaniTube\Observability;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Observability\Console\HealthCommand;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityRegistry::class, fn ($app): CapabilityRegistry => new CapabilityRegistry(
            container: $app,
            detectors: (array) config('capabilities.detectors', []),
        ));

        $this->app->singleton(SchedulerHeartbeat::class, fn ($app): SchedulerHeartbeat => new SchedulerHeartbeat(
            $app->make(Cache::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class]);
        }
    }
}
