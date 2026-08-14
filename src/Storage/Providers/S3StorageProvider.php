<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Amazon S3, and the recommended production target.
 */
final class S3StorageProvider extends FilesystemStorageProvider
{
    public function __construct(Filesystem $disk, string $name = 's3')
    {
        parent::__construct($disk, $name, temporaryUrls: true);
    }
}
