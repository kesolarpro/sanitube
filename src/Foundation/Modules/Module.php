<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Modules;

use Illuminate\Support\Str;

/**
 * A bounded context inside the modular monolith.
 *
 * A module owns its own directory, namespace and (optionally) service
 * provider, routes, migrations, translations and views. Nothing outside a
 * module may reach into its internals: cross-module communication goes
 * through published contracts and events.
 */
final readonly class Module
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
    ) {}

    /**
     * Stable lower-case identifier, used for translation and view namespaces.
     */
    public function key(): string
    {
        return Str::snake($this->name, '-');
    }

    /**
     * Conventional service provider class, whether or not it exists yet.
     */
    public function providerClass(): string
    {
        return $this->namespace.'\\'.$this->name.'ServiceProvider';
    }

    public function hasProvider(): bool
    {
        return class_exists($this->providerClass());
    }

    /**
     * Absolute path inside the module directory.
     */
    public function path(string $relative = ''): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $this->path : $this->path.'/'.$relative;
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }
}
