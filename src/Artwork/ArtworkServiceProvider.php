<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SaniTube\Artwork\Console\MeasureArtworkCommand;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Listeners\MeasureWhenVerified;
use SaniTube\Artwork\Measurers\NativeImageMeasurer;
use SaniTube\Artwork\Measurers\UnavailableImageMeasurer;
use SaniTube\Assets\Events\AssetVerified;

/**
 * Wires the artwork module.
 *
 * The measurer is bound to whichever implementation this installation can
 * actually use, decided once here rather than by every caller asking whether
 * `getimagesize` exists. An installation without it gets the null object and
 * behaves consistently everywhere instead of failing at one call site.
 */
final class ArtworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageMeasurer::class, static function (): ImageMeasurer {
            $measurer = new NativeImageMeasurer;

            return $measurer->isAvailable() ? $measurer : new UnavailableImageMeasurer;
        });
    }

    public function boot(): void
    {
        Event::listen(AssetVerified::class, MeasureWhenVerified::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MeasureArtworkCommand::class]);
        }
    }
}
