<?php

declare(strict_types=1);

namespace SaniTube\Api;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $perMinute = (int) config('sanitube.api.rate_limit_per_minute', 60);

            return Limit::perMinute($perMinute)->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
