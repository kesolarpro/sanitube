<?php

declare(strict_types=1);

namespace SaniTube\Catalog;

use Illuminate\Support\ServiceProvider;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\CompositionContributor;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Observers\CompositionContributorObserver;
use SaniTube\Catalog\Observers\CompositionObserver;
use SaniTube\Catalog\Observers\ExternalIdentifierObserver;
use SaniTube\Catalog\Observers\TrackObserver;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Composition::observe(CompositionObserver::class);
        CompositionContributor::observe(CompositionContributorObserver::class);
        Track::observe(TrackObserver::class);
        ExternalIdentifier::observe(ExternalIdentifierObserver::class);
    }
}
