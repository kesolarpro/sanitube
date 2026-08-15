<?php

declare(strict_types=1);

namespace SaniTube\AI;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SaniTube\AI\Contracts\AiProvider;

final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class, function (): AiManager {
            /** @var array<string, array<string, mixed>> $providers */
            $providers = (array) config('ai.providers', []);

            // The configuration boundary. `(string) config(...)` alone is what
            // broke: with SANITUBE_AI_PROVIDER=null in a .env file the config
            // value is PHP null, the env() default never applies because the
            // key is present, and the cast produces an empty provider name.
            return new AiManager($providers, AiManager::normaliseProviderName(config('ai.default')));
        });

        // Resolving the contract gives the configured default, so a caller
        // that genuinely only needs "a model" does not have to know the
        // manager exists. Callers that need the ledger use RunPrompt instead,
        // which is almost all of them.
        $this->app->bind(AiProvider::class, fn (Application $app): AiProvider => $app->make(AiManager::class)->default());
    }
}
