<?php

declare(strict_types=1);

namespace SaniTube\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\UnknownStorageProvider;
use SaniTube\Storage\Providers\BackblazeB2Provider;
use SaniTube\Storage\Providers\CloudflareR2Provider;
use SaniTube\Storage\Providers\FilesystemStorageProvider;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\Providers\S3StorageProvider;

/**
 * Resolves configured storage providers by name.
 *
 * Providers are described in `config/storage.php`; each maps a SaniTube
 * provider name to a Laravel filesystem disk. Adding a future provider means
 * adding a driver here and a disk in `config/filesystems.php`.
 */
final class StorageManager
{
    /** @var array<string, StorageProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, array{driver?: string, disk?: string}>  $providers
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    public function default(): StorageProvider
    {
        return $this->provider($this->defaultProvider);
    }

    public function defaultName(): string
    {
        return $this->defaultProvider;
    }

    public function provider(string $name): StorageProvider
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    private function resolve(string $name): StorageProvider
    {
        $config = $this->providers[$name] ?? throw UnknownStorageProvider::for($name, $this->names());

        $disk = $this->filesystems->disk($config['disk'] ?? $name);

        return match ($config['driver'] ?? $name) {
            'local' => new LocalStorageProvider($disk, $name),
            's3' => new S3StorageProvider($disk, $name),
            'r2' => new CloudflareR2Provider($disk, $name),
            'b2' => new BackblazeB2Provider($disk, $name),
            default => new FilesystemStorageProvider($disk, $name),
        };
    }
}
