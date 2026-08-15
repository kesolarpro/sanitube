<?php

declare(strict_types=1);

namespace SaniTube\Releases;

use Illuminate\Support\ServiceProvider;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Observers\ReleaseObserver;

final class ReleasesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Release::observe(ReleaseObserver::class);
    }
}
