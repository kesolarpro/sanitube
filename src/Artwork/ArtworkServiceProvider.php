<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SaniTube\Artwork\Console\MeasureArtworkCommand;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Listeners\MeasureWhenVerified;
use SaniTube\Artwork\Measurers\NativeImageMeasurer;
use SaniTube\Artwork\Measurers\UnavailableImageMeasurer;
use SaniTube\Artwork\Providers\OpenAiImageProvider;
use SaniTube\Artwork\Providers\UnavailableImageProvider;
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

        $this->app->singleton(ImageProvider::class, static function (): ImageProvider {
            $name = config('artwork.default_provider', 'none');
            $name = is_string($name) && trim($name) !== '' ? trim($name) : 'none';

            $configuration = config('artwork.providers.'.$name);
            $configuration = is_array($configuration) ? $configuration : [];

            $driver = $configuration['driver'] ?? $name;

            // Dispatched on the driver rather than the key, so an operator may
            // name a provider entry whatever suits them -- `openai-eu`, say --
            // without the platform deciding it does not recognise it.
            return match (is_string($driver) ? $driver : 'none') {
                'openai' => new OpenAiImageProvider($configuration),
                default => new UnavailableImageProvider,
            };
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
