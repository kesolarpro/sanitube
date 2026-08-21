<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Host;

use Symfony\Component\Process\ExecutableFinder;

/**
 * The probe that asks the actual machine.
 *
 * Nothing here executes a process: detection is reads and lookups only, so
 * inspecting a host is safe on any host — including one where `proc_open` is
 * the very thing being detected as unavailable.
 */
final readonly class RealHostProbe implements HostProbe
{
    public function __construct(private ExecutableFinder $finder = new ExecutableFinder) {}

    public function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function phpBinary(): string
    {
        // Narrower than the interface on purpose: this PHP always knows its
        // own binary. The nullable contract exists for machines described
        // second-hand, where nobody may have recorded it.
        return PHP_BINARY;
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function binary(string $name): ?string
    {
        return $this->finder->find($name);
    }

    public function effectiveUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    public function iniValue(string $name): ?string
    {
        $value = ini_get($name);

        return $value === false || $value === '' ? null : $value;
    }

    public function functionUsable(string $name): bool
    {
        if (! function_exists($name)) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');

        foreach (explode(',', $disabled) as $entry) {
            if (trim($entry) === $name) {
                return false;
            }
        }

        return true;
    }
}
