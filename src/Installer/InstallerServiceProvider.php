<?php

declare(strict_types=1);

namespace SaniTube\Installer;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SaniTube\Installer\Console\InstallCommand;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationService;
use SaniTube\Observability\Capabilities\CapabilityRegistry;

final class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound to the real paths rather than constructed inside the service,
        // so a test can point both at a temporary directory and never risk
        // rewriting the developer's own .env.
        $this->app->bind(EnvironmentFile::class, fn (Application $app): EnvironmentFile => new EnvironmentFile(
            $app->environmentFilePath(),
        ));

        $this->app->bind(InstallationService::class, fn (Application $app): InstallationService => new InstallationService(
            environment: $app->make(EnvironmentFile::class),
            capabilities: $app->make(CapabilityRegistry::class),
            artisan: $app->make(Artisan::class),
            examplePath: $app->basePath('.env.example'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}
