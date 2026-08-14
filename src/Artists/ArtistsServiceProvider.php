<?php

declare(strict_types=1);

namespace SaniTube\Artists;

use Illuminate\Support\ServiceProvider;
use SaniTube\Artists\Models\Artist;
use SaniTube\Artists\Observers\ArtistObserver;

final class ArtistsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artist::observe(ArtistObserver::class);
    }
}
