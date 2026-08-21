<?php

declare(strict_types=1);

namespace Tests\Support\Deployment;

use SaniTube\Deployment\Host\HostProbe;

/**
 * A machine described as arrays.
 *
 * The point of the {@see HostProbe} seam is that every conclusion the
 * inspector draws can be exercised against any machine shape — a cPanel
 * server, a Debian VPS, a BSD box — without the test suite needing one. This
 * is that machine: whatever the constructor says exists, exists.
 */
final readonly class FakeHostProbe implements HostProbe
{
    /**
     * @param  list<string>  $directories
     * @param  array<string, string>  $files  path => contents
     * @param  array<string, string>  $binaries  name => resolved path
     * @param  array<string, string>  $ini  name => value
     * @param  list<string>  $usableFunctions
     */
    public function __construct(
        private string $osFamily = 'Linux',
        private string $phpVersion = '8.3.0',
        private ?string $phpBinary = '/usr/bin/php',
        private array $directories = [],
        private array $files = [],
        private array $binaries = [],
        private ?int $effectiveUserId = 1000,
        private array $ini = [],
        private array $usableFunctions = ['proc_open'],
    ) {}

    public function osFamily(): string
    {
        return $this->osFamily;
    }

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function phpBinary(): ?string
    {
        return $this->phpBinary;
    }

    public function isDirectory(string $path): bool
    {
        return in_array($path, $this->directories, true);
    }

    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function binary(string $name): ?string
    {
        return $this->binaries[$name] ?? null;
    }

    public function effectiveUserId(): ?int
    {
        return $this->effectiveUserId;
    }

    public function iniValue(string $name): ?string
    {
        return $this->ini[$name] ?? null;
    }

    public function functionUsable(string $name): bool
    {
        return in_array($name, $this->usableFunctions, true);
    }
}
