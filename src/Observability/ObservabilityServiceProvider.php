<?php

declare(strict_types=1);

namespace SaniTube\Observability;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Observability\Certification\CertificationLedger;
use SaniTube\Observability\Console\HealthCommand;
use SaniTube\Observability\Console\MailCertifyCommand;
use SaniTube\Observability\Console\ProvidersCommand;
use SaniTube\Observability\Console\RefreshOperationalHealthCommand;
use SaniTube\Observability\Health\OperationalHealthStore;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CertificationLedger::class, fn (): CertificationLedger => new CertificationLedger(
            storage_path(),
        ));

        $this->app->singleton(CapabilityRegistry::class, fn ($app): CapabilityRegistry => new CapabilityRegistry(
            container: $app,
            detectors: (array) config('capabilities.detectors', []),
        ));

        $this->app->singleton(SchedulerHeartbeat::class, fn ($app): SchedulerHeartbeat => new SchedulerHeartbeat(
            $app->make(Cache::class),
        ));

        $this->app->singleton(OperationalHealthStore::class, fn ($app): OperationalHealthStore => new OperationalHealthStore(
            cache: $app->make(Cache::class),
            key: (string) config('sanitube.health.operational.cache_key', 'sanitube:health:operational'),
            staleAfterSeconds: (int) config('sanitube.health.operational.stale_after_seconds', 900),
            retentionSeconds: (int) config('sanitube.health.operational.retention_seconds', 604800),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class, RefreshOperationalHealthCommand::class, ProvidersCommand::class, MailCertifyCommand::class]);
        }
    }
}
