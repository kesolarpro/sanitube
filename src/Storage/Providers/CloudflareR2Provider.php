<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Cloudflare R2 — S3-compatible, addressed through a custom endpoint.
 */
final class CloudflareR2Provider extends FilesystemStorageProvider
{
    public function __construct(Filesystem $disk, string $name = 'r2')
    {
        parent::__construct($disk, $name, temporaryUrls: true);
    }
}
