<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use Illuminate\Contracts\Filesystem\Filesystem;
use SaniTube\Storage\Concerns\MintsUploadUrls;
use SaniTube\Storage\Contracts\SupportsDirectUpload;

/**
 * Amazon S3, and the recommended production target.
 */
final class S3StorageProvider extends FilesystemStorageProvider implements SupportsDirectUpload
{
    use MintsUploadUrls;

    public function __construct(Filesystem $disk, string $name = 's3')
    {
        parent::__construct($disk, $name, temporaryUrls: true);
    }
}
