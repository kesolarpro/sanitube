<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use Illuminate\Contracts\Foundation\Application;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

/**
 * The classic cPanel failure mode: a deployment that cannot write to
 * storage/ or bootstrap/cache and fails at the first request instead of at
 * install time.
 */
final readonly class StoragePermissionsDetector implements CapabilityDetector
{
    public function __construct(private Application $app) {}

    public function detect(): Capability
    {
        $paths = [
            $this->app->storagePath('app'),
            $this->app->storagePath('framework'),
            $this->app->storagePath('logs'),
            $this->app->bootstrapPath('cache'),
        ];

        $unwritable = array_values(array_filter(
            $paths,
            static fn (string $path): bool => ! is_dir($path) || ! is_writable($path),
        ));

        if ($unwritable !== []) {
            return Capability::unavailable(
                key: 'storage_permissions',
                label: 'Storage permissions',
                detail: 'Not writable: '.implode(', ', $unwritable).'.',
                remediation: 'Create the directories and make them writable by the web server user '
                    .'(cPanel: 0755 owned by the account user; VPS: chown -R www-data:www-data storage bootstrap/cache).',
            );
        }

        return Capability::available(
            key: 'storage_permissions',
            label: 'Storage permissions',
            detail: 'storage/ and bootstrap/cache are writable.',
        );
    }
}
