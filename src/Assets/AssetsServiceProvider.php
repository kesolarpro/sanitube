<?php

declare(strict_types=1);

namespace SaniTube\Assets;

use Illuminate\Support\ServiceProvider;
use SaniTube\Assets\Console\CleanUpStagingCommand;
use SaniTube\Assets\Console\VerifyAssetsCommand;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Observers\AssetObserver;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Assets\Storage\ByteCapFilter;

final class AssetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssetObjectKeys::class);

        // Registered at boot rather than on first use: a stream filter that is
        // missing when an upload starts fails as a silently uncapped upload,
        // which is the one outcome the cap exists to prevent.
        ByteCapFilter::register();
    }

    public function boot(): void
    {
        // Registered as an observer rather than called from a service, so the
        // asset invariants hold for every write path there will ever be.
        Asset::observe(AssetObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyAssetsCommand::class,
                CleanUpStagingCommand::class,
            ]);
        }
    }
}
