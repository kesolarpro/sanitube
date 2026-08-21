<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

/**
 * One track as it appears in a delivery package.
 *
 * **The uuid and the filename are both here on purpose.** The filename is what
 * an operator sees in their file manager and types into an intake form; the
 * uuid is what the track *is*. Two tracks called "Intro" on the same album
 * sanitise to the same string and are still two different recordings, and a
 * package that carried only the name would have no way to say so.
 *
 * The checksum is here for the operator, not for SaniTube: after uploading a
 * master to a portal, the only way to know the right bytes arrived is to
 * compare a digest, and a package that made you go and compute one yourself is
 * a package nobody checks.
 */
final readonly class PackagedTrack
{
    /**
     * @param  string  $trackUuid  what this recording is
     * @param  string  $assetUuid  which stored master carries it
     * @param  string  $storageKey  where that master lives, for an operator with filesystem access
     * @param  string  $fileName  what to call it in the package — a rendering, never an identity
     * @param  array<string, string>  $columns  the metadata row, keyed by heading
     */
    public function __construct(
        public int $discNumber,
        public int $trackNumber,
        public string $trackUuid,
        public string $assetUuid,
        public string $storageKey,
        public string $fileName,
        public ?string $checksum,
        public ?int $bytes,
        public array $columns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disc_number' => $this->discNumber,
            'track_number' => $this->trackNumber,
            'track_uuid' => $this->trackUuid,
            'asset_uuid' => $this->assetUuid,
            'storage_key' => $this->storageKey,
            'file_name' => $this->fileName,
            'sha256' => $this->checksum,
            'bytes' => $this->bytes,
        ];
    }
}
