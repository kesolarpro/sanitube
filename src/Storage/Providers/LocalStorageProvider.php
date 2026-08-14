<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Local disk storage.
 *
 * Supported for development, single-server installs and shared cPanel accounts
 * with no object storage. It cannot mint expiring URLs, so playback and
 * distributor hand-off must route through the application instead.
 */
final class LocalStorageProvider extends FilesystemStorageProvider
{
    public function __construct(Filesystem $disk, string $name = 'local')
    {
        parent::__construct($disk, $name, temporaryUrls: false);
    }
}
