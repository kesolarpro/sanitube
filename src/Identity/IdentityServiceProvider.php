<?php

declare(strict_types=1);

namespace SaniTube\Identity;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use SaniTube\Identity\Http\Middleware\EnsureUserCan;
use SaniTube\Identity\Http\Middleware\EnsureUserIsActive;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('active', EnsureUserIsActive::class);
        $router->aliasMiddleware('can.role', EnsureUserCan::class);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\CreateUserCommand::class]);
        }
    }
}
