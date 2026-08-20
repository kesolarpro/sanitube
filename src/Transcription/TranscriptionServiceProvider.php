<?php

declare(strict_types=1);

namespace SaniTube\Transcription;

use Illuminate\Support\ServiceProvider;
use SaniTube\Transcription\Contracts\TranscriptionProvider;

/**
 * Wires the transcription module.
 *
 * The manager is a singleton because it caches resolved providers, and the
 * default provider is bound so that a caller wanting "whatever this
 * installation transcribes with" does not have to know the manager exists.
 */
final class TranscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TranscriptionManager::class, function (): TranscriptionManager {
            /** @var array<string, array<string, mixed>> $providers */
            $providers = (array) config('transcription.providers', []);

            return new TranscriptionManager(
                $providers,
                TranscriptionManager::normaliseProviderName(config('transcription.provider')),
            );
        });

        $this->app->bind(
            TranscriptionProvider::class,
            static fn ($app): TranscriptionProvider => $app->make(TranscriptionManager::class)->default(),
        );
    }
}
