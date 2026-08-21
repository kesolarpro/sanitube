<?php

declare(strict_types=1);

namespace SaniTube\Worker;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use SaniTube\Worker\Client\WorkerClient;

/**
 * Wires the worker boundary.
 *
 * The registry is a singleton because it is filled by other modules at boot:
 * whichever module owns a kind of work registers its own handler, and this
 * module never learns what any of them do. That is what stops it becoming the
 * place every subsystem reaches into.
 *
 * No handler is registered here. There is nothing this module can do by itself,
 * which is the correct amount.
 */
final class WorkerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerRegistry::class);

        $this->app->singleton(WorkerClient::class, fn ($app): WorkerClient => new WorkerClient(
            $app->make(HttpFactory::class),
            $app->make(Cache::class),
            (array) config('worker', []),
        ));
    }
}
