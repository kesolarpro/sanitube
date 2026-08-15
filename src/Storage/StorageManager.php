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
 *
 * **Configuration is read once, when the manager is constructed.** That is
 * deliberate: a default provider that can change under a running process is
 * harder to reason about than one that cannot, and an upload half-written to
 * one provider and half to another is not a state worth being able to reach.
 *
 * The consequence is worth stating plainly, because it surprises people:
 * changing `storage.default` after the container has resolved this manager has
 * no effect on it. Rebuild or rebind the singleton — or, in a test, set the
 * configuration before resolving it. Addressing a specific provider needs no
 * such thing: {@see provider()} and {@see register()} work at any time.
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
        return array_values(array_unique(array_merge(
            array_keys($this->providers),
            array_keys($this->resolved),
        )));
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]) || isset($this->resolved[$name]);
    }

    /**
     * Register a provider instance under a name.
     *
     * The seam that lets a provider come from somewhere other than
     * `config/storage.php` — a package, or a test that needs a store it can
     * make fail on demand. Without it, every test of the upload workflow would
     * have to go through a real disk, and "the provider failed halfway" would
     * be untestable.
     */
    public function register(string $name, StorageProvider $provider): void
    {
        $this->resolved[$name] = $provider;
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
