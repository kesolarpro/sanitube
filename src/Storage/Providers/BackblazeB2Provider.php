<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use Illuminate\Contracts\Filesystem\Filesystem;
use SaniTube\Storage\Concerns\MintsUploadUrls;
use SaniTube\Storage\Contracts\SupportsDirectUpload;

/**
 * Backblaze B2 through its S3-compatible API.
 */
final class BackblazeB2Provider extends FilesystemStorageProvider implements SupportsDirectUpload
{
    use MintsUploadUrls;

    public function __construct(Filesystem $disk, string $name = 'b2')
    {
        parent::__construct($disk, $name, temporaryUrls: true);
    }
}
