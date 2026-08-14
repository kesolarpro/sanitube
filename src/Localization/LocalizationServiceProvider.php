<?php

declare(strict_types=1);

namespace SaniTube\Localization;

use Illuminate\Support\ServiceProvider;

final class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocaleRegistry::class, fn (): LocaleRegistry => new LocaleRegistry(
            supported: (array) config('localization.supported', []),
            configuredDefault: (string) config('localization.default', 'en'),
            configuredFallback: (string) config('localization.fallback', 'en'),
        ));

        $this->app->singleton(LocaleNegotiator::class, fn ($app): LocaleNegotiator => new LocaleNegotiator(
            locales: $app->make(LocaleRegistry::class),
            useAcceptLanguage: (bool) config('localization.negotiation.use_accept_language', true),
        ));
    }

    public function boot(): void
    {
        $registry = $this->app->make(LocaleRegistry::class);

        $this->app->setLocale($registry->default()->code);
        $this->app->setFallbackLocale($registry->fallback()->code);
    }
}
