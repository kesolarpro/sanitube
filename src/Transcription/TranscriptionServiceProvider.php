<?php

declare(strict_types=1);

namespace SaniTube\Transcription;

use Illuminate\Support\ServiceProvider;
use SaniTube\Assets\Events\AssetStored;
use SaniTube\Transcription\Console\TranscribeBacklogCommand;
use SaniTube\Transcription\Contracts\TranscriptionProvider;
use SaniTube\Transcription\Listeners\TranscribeWhenStored;

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

    /**
     * Wires transcription to the one moment there is something new to hear.
     *
     * **One listener, and off by default** -- the opposite of deduplication,
     * which listens to two events and always runs. The difference is the bill:
     * comparing checksums is free and local, and a supplier call is neither.
     * An installation that has not decided transcription is worth paying for
     * transcribes only what somebody asks for.
     */
    public function boot(): void
    {
        $this->app['events']->listen(AssetStored::class, TranscribeWhenStored::class);

        if ($this->app->runningInConsole()) {
            $this->commands([TranscribeBacklogCommand::class]);
        }
    }
}
