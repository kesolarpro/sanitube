<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the modular monolith.
 *
 * Modules are declared in `config/sanitube.php`. This provider turns that flat
 * list into a {@see ModuleRegistry}, registers each module's own service
 * provider when it has one, and wires the conventional resources every module
 * may expose:
 *
 *   src/<Module>/Database/Migrations   → migrations
 *   src/<Module>/Resources/lang        → translations, namespaced by module key
 *   src/<Module>/Resources/views       → views, namespaced by module key
 *   src/<Module>/Routes/web.php        → "web" middleware group
 *   src/<Module>/Routes/api.php        → "api" middleware group, API prefix
 *   src/<Module>/Routes/console.php    → console routes
 *
 * Every one of those is optional: a module that only publishes contracts needs
 * nothing but a directory.
 */
final class ModuleLoaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn (): ModuleRegistry => new ModuleRegistry(
            names: (array) config('sanitube.modules', []),
            basePath: (string) config('sanitube.paths.modules', $this->app->basePath('src')),
        ));

        foreach ($this->registry()->installed() as $module) {
            if ($module->hasProvider()) {
                $this->app->register($module->providerClass());
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->registry()->installed() as $module) {
            $this->bootResources($module);
            $this->bootRoutes($module);
        }
    }

    private function bootResources(Module $module): void
    {
        if (is_dir($migrations = $module->path('Database/Migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (is_dir($lang = $module->path('Resources/lang'))) {
            $this->loadTranslationsFrom($lang, $module->key());
        }

        if (is_dir($views = $module->path('Resources/views'))) {
            $this->loadViewsFrom($views, $module->key());
        }
    }

    private function bootRoutes(Module $module): void
    {
        if ($this->app->runningInConsole() && is_file($console = $module->path('Routes/console.php'))) {
            require $console;
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        if (is_file($web = $module->path('Routes/web.php'))) {
            Route::middleware('web')->group($web);
        }

        if (is_file($api = $module->path('Routes/api.php'))) {
            Route::middleware('api')
                ->prefix((string) config('sanitube.api.prefix', 'api'))
                ->group($api);
        }
    }

    private function registry(): ModuleRegistry
    {
        return $this->app->make(ModuleRegistry::class);
    }
}
