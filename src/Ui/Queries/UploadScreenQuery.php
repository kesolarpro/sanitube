<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use SaniTube\Assets\Services\UploadAdmission;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\StorageManager;

/**
 * What the upload screen is allowed to promise.
 *
 * Everything here is *measured from this installation* rather than assumed:
 * whether the configured provider can take a direct upload, what each kind's
 * ceiling is, and what PHP itself will accept.
 *
 * That last one matters more than it looks. An operator who raised
 * `SANITUBE_MAX_MASTER_BYTES` to two gigabytes and left `post_max_size` at
 * eight megabytes has an installation that refuses every master, and a screen
 * advertising the two-gigabyte figure would be lying to them in the one place
 * they would look. So the screen shows the **effective** ceiling — the lower of
 * the two on the relayed path — and says which one is binding.
 *
 * **No provider name, no bucket, no path, no signed URL.** The screen learns
 * that a direct upload is possible, not where anything lives.
 */
final readonly class UploadScreenQuery
{
    public function __construct(
        private StorageManager $storage,
        private UploadAdmission $admission,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $direct = $this->storage->default() instanceof SupportsDirectUpload;
        $phpLimit = $this->admission->phpPostLimitBytes();

        $kinds = [];

        foreach ($this->admission->uploadableKinds() as $kind) {
            $configured = $this->admission->maximumBytes($kind);

            // On the direct path the bytes never enter PHP, so PHP's limit is
            // not the binding one. On the relayed path it usually is.
            $effective = $direct || $phpLimit <= 0
                ? $configured
                : min($configured, $phpLimit);

            $kinds[] = [
                'value' => $kind->value,
                'maximum_bytes' => $configured,
                'effective_maximum_bytes' => $effective,
                // Named so the screen can explain a ceiling the operator did
                // not set and cannot find in this application's configuration.
                'limited_by_php' => ! $direct && $phpLimit > 0 && $phpLimit < $configured,
                'accepted_types' => $this->admission->acceptedTypes($kind),
            ];
        }

        return [
            'direct' => $direct,
            'php_post_limit_bytes' => $phpLimit,
            'kinds' => $kinds,
        ];
    }
}
