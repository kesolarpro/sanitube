<?php

declare(strict_types=1);

namespace SaniTube\Ui;

use Illuminate\Support\ServiceProvider;

final class UiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Navigation\NavigationTree::class);
    }
}
