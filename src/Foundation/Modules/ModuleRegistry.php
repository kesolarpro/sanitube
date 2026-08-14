<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Modules;

use Countable;
use IteratorAggregate;
use SaniTube\Foundation\Modules\Exceptions\UnknownModuleException;
use Traversable;

/**
 * The authoritative list of modules that make up the application.
 *
 * @implements IteratorAggregate<string, Module>
 */
final class ModuleRegistry implements Countable, IteratorAggregate
{
    /** @var array<string, Module> */
    private array $modules = [];

    /**
     * @param  iterable<int|string, string>  $names
     */
    public function __construct(
        iterable $names,
        private readonly string $basePath,
        private readonly string $rootNamespace = 'SaniTube',
    ) {
        foreach ($names as $name) {
            $module = new Module(
                name: $name,
                namespace: $this->rootNamespace.'\\'.$name,
                path: rtrim($this->basePath, '/').'/'.$name,
            );

            $this->modules[$module->key()] = $module;
        }
    }

    /**
     * @return array<string, Module>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Modules whose directory actually exists on disk.
     *
     * @return array<string, Module>
     */
    public function installed(): array
    {
        return array_filter($this->modules, static fn (Module $module): bool => $module->exists());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_map(static fn (Module $module): string => $module->name, $this->modules));
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$this->normalise($name)]);
    }

    public function get(string $name): Module
    {
        return $this->modules[$this->normalise($name)]
            ?? throw UnknownModuleException::for($name, $this->names());
    }

    public function count(): int
    {
        return count($this->modules);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->modules);
    }

    private function normalise(string $name): string
    {
        return (new Module($name, '', ''))->key();
    }
}
