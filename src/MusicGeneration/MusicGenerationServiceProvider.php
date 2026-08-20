<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use Illuminate\Support\ServiceProvider;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Providers\HttpGeneratedAudioReader;
use SaniTube\MusicGeneration\Services\GenerationCircuitBreaker;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;

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

        $this->app->singleton(ProviderCapabilities::class, function (): ProviderCapabilities {
            /** @var array<string, array<string, mixed>> $providers */
            $providers = (array) config('generation.providers', []);

            // The `disabled_capabilities` entries live alongside each
            // provider's own settings rather than in a block of their own, so
            // an operator configuring a supplier sees everything about it in
            // one place.
            return new ProviderCapabilities($providers);
        });

        $this->app->bind(SelectGenerationProvider::class, function ($app): SelectGenerationProvider {
            return new SelectGenerationProvider(
                $app->make(MusicGenerationManager::class),
                $app->make(ProviderCapabilities::class),
                $app->make(GenerationCircuitBreaker::class),
                self::preference(config('generation.preference')),
            );
        });

        $this->app->bind(GeneratedAudioReader::class, HttpGeneratedAudioReader::class);
    }

    /**
     * Operator preference, from a config array or a comma-separated string.
     *
     * Both, because a preference expressed in `.env` can only be a string, and
     * an installation that publishes the config file will want an array. The
     * same value has to mean the same thing either way or the setting becomes
     * one an operator tests rather than reads.
     *
     * @return list<string>
     */
    private static function preference(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $names = [];

        foreach ($configured as $name) {
            if (! is_string($name)) {
                continue;
            }

            $trimmed = trim($name);

            if ($trimmed !== '') {
                $names[] = $trimmed;
            }
        }

        return $names;
    }
}
