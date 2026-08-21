<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use SaniTube\Distribution\Export\DeliveryPackageKeys;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * What has been packaged for this release, if anything.
 *
 * Read from the manifest in storage rather than from a row in the database, and
 * deliberately: the package *is* the two files. A table saying a package exists
 * beside a bucket that no longer holds one is a screen that lies, and the thing
 * an operator is about to upload is the file, not the row.
 *
 * **No object key and no link leave this class.** The screen shows what each
 * master is called *inside the package*, its digest and its size — everything a
 * person needs to check an upload against — and asks for a link one master at a
 * time, through the same minting endpoint every other screen uses. A page that
 * arrived carrying twelve signed URLs would have created twelve credentials for
 * somebody who opened it to read a filename.
 */
final readonly class DeliveryPackageQuery
{
    public function __construct(
        private StorageManager $storage,
        private DeliveryPackageKeys $keys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Release $release): array
    {
        return [
            'release' => [
                'uuid' => $release->uuid,
                'title' => $release->title,
            ],

            // Said by the server, not inferred from a disabled button: "why can
            // I not build this" is a question this screen has to answer.
            'release_is_ready' => $release->status === ReleaseStatus::Ready,

            'package' => $this->manifest($release),
        ];
    }

    /**
     * The manifest, or nothing.
     *
     * A storage failure is reported as *no package* rather than as an error:
     * the remedy is the same button either way, and a screen that 500s because
     * a bucket hiccupped is one an operator cannot use to find out what is
     * wrong.
     *
     * @return array<string, mixed>|null
     */
    private function manifest(Release $release): ?array
    {
        try {
            $store = $this->storage->default();
            $key = $this->keys->manifest($release);

            if (! $store->exists($key)) {
                return null;
            }

            /** @var array<string, mixed>|null $manifest */
            $manifest = json_decode($store->get($key), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($manifest)) {
            return null;
        }

        /** @var list<array<string, mixed>> $tracks */
        $tracks = (array) ($manifest['tracks'] ?? []);

        return [
            'built_at' => (string) ($manifest['built_at'] ?? ''),
            'track_count' => (int) ($manifest['track_count'] ?? count($tracks)),
            'warnings' => array_values(array_map(
                static fn (mixed $warning): string => (string) $warning,
                (array) ($manifest['warnings'] ?? []),
            )),
            'tracks' => array_map(
                static fn (array $track): array => [
                    'disc_number' => (int) ($track['disc_number'] ?? 0),
                    'track_number' => (int) ($track['track_number'] ?? 0),
                    'file_name' => (string) ($track['file_name'] ?? ''),
                    'sha256' => (string) ($track['sha256'] ?? ''),
                    'bytes' => $track['bytes'] === null ? null : (int) $track['bytes'],

                    // The asset's public uuid, so the screen can ask the
                    // ordinary minting endpoint for a link. Never the object
                    // key: where a master lives is not a browser's business.
                    'asset_uuid' => (string) ($track['asset_uuid'] ?? ''),
                ],
                $tracks,
            ),
        ];
    }
}
