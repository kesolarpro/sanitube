<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use Illuminate\Support\ServiceProvider;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Providers\HttpGeneratedAudioReader;

final class MusicGenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MusicGenerationManager::class, function (): MusicGenerationManager {
            /** @var array<string, array<string, mixed>> $providers */
            $providers = (array) config('generation.providers', []);

            // The configuration boundary, matching AI-001: empty means the
            // disabled provider, an unknown non-empty name is an error.
            return new MusicGenerationManager(
                $providers,
                MusicGenerationManager::normaliseProviderName(config('generation.default')),
            );
        });

        $this->app->bind(
            MusicGenerationProvider::class,
            fn ($app): MusicGenerationProvider => $app->make(MusicGenerationManager::class)->default(),
        );

        $this->app->bind(GeneratedAudioReader::class, HttpGeneratedAudioReader::class);
    }
}
