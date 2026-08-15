<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

use Illuminate\Support\ServiceProvider;
use SaniTube\Distribution\Contracts\Distributor;

final class DistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DistributorManager::class, function (): DistributorManager {
            /** @var array<string, array<string, mixed>> $distributors */
            $distributors = (array) config('distribution.distributors', []);

            return new DistributorManager(
                $distributors,
                DistributorManager::normaliseName(config('distribution.default')),
            );
        });

        $this->app->bind(
            Distributor::class,
            fn ($app): Distributor => $app->make(DistributorManager::class)->default(),
        );
    }
}
