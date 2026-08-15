<?php

declare(strict_types=1);

namespace SaniTube\Assets;

use Illuminate\Support\ServiceProvider;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Observers\AssetObserver;

final class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registered as an observer rather than called from a service, so the
        // asset invariants hold for every write path there will ever be.
        Asset::observe(AssetObserver::class);
    }
}
