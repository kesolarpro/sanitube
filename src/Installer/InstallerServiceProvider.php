<?php

declare(strict_types=1);

namespace SaniTube\Installer;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use SaniTube\Installer\Console\DeployCommand;
use SaniTube\Installer\Console\InstallCommand;
use SaniTube\Installer\Http\Controllers\InstallController;
use SaniTube\Installer\Http\Middleware\EnsureInstallable;
use SaniTube\Installer\Http\Middleware\UseFilesystemState;
use SaniTube\Installer\Services\DeploymentLock;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationJournal;
use SaniTube\Installer\Services\InstallationLock;
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

        $this->app->bind(InstallationLock::class, fn (Application $app): InstallationLock => new InstallationLock(
            $app->storagePath(),
        ));

        $this->app->bind(InstallationJournal::class, fn (Application $app): InstallationJournal => new InstallationJournal(
            $app->storagePath(),
        ));

        $this->app->bind(DeploymentLock::class, fn (Application $app): DeploymentLock => new DeploymentLock(
            $app->storagePath(),
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
            $this->commands([InstallCommand::class, DeployCommand::class]);
        }

        $this->bootInstallerRoutes();
    }

    /**
     * INS-002 — the web installer's routes, with their middleware spelled out.
     *
     * Deliberately not the `web` group, and not `src/Installer/Routes/web.php`
     * either. The installer runs before the schema exists, so its session and
     * cache have to be on the filesystem — and `StartSession` reads the driver
     * from configuration as it runs, which means the swap has to happen
     * *before* it. Route middleware runs after a group's, so joining the group
     * would put `UseFilesystemState` too late to matter.
     *
     * The stack below is the `web` group minus what the installer cannot have
     * (a database session, the locale middleware's translations) and plus the
     * two guards that make an unauthenticated installer defensible.
     */
    private function bootInstallerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware([
            // First. Everything after it depends on the session working.
            UseFilesystemState::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            // The form is a POST from a browser like any other, and there is
            // no argument for the one unauthenticated write in the platform
            // being the one without CSRF.
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            // Last, so a locked installation is a 404 rather than a page that
            // renders and then refuses.
            EnsureInstallable::class,
        ])->group(function (): void {
            Route::get('install', [InstallController::class, 'show'])->name('install');
            Route::post('install', [InstallController::class, 'store'])->name('install.store');
        });
    }
}
