<?php

declare(strict_types=1);

namespace SaniTube\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\ServiceProvider;
use SaniTube\Storage\Contracts\StorageProvider;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageManager::class, fn ($app): StorageManager => new StorageManager(
            filesystems: $app->make(FilesystemFactory::class),
            providers: (array) config('storage.providers', []),
            defaultProvider: (string) config('storage.default', 'local'),
        ));

        $this->app->bind(
            StorageProvider::class,
            fn ($app): StorageProvider => $app->make(StorageManager::class)->default(),
        );
    }
}
