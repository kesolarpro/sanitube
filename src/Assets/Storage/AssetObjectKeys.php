<?php

declare(strict_types=1);

namespace SaniTube\Assets\Storage;

use SaniTube\Assets\Enums\AssetKind;

/**
 * Where an asset's bytes live.
 *
 * One rule underpins the whole scheme: **the original filename is never part
 * of the identity of an object.** Two people uploading `master.wav` are not
 * uploading the same thing, a name is not unique, and a name supplied by a
 * client is not trustworthy. The key is derived from the asset's own UUID,
 * which the server generates and nothing outside can influence:
 *
 *     masters/{uuid}/original.wav
 *     artwork/{uuid}/original.jpg
 *     staging/{uuid}/original.wav
 *
 * Every key is therefore server-generated, deterministic and traversal-free by
 * construction. Determinism is what makes a failed upload safe to retry: the
 * second attempt addresses exactly the same object as the first, so a retry
 * overwrites rather than accumulates.
 *
 * The trailing extension is cosmetic. It exists so an object pulled out of a
 * store by hand opens in the right application, and it is derived from a
 * character allowlist — never trusted, never used to decide anything.
 */
final readonly class AssetObjectKeys
{
    /**
     * Reserved prefix for uploads that have not yet been accepted. Nothing
     * outside this prefix is ever swept by the staging cleanup, and nothing
     * inside it is ever an asset of record.
     */
    public const STAGING_PREFIX = 'staging';

    /** The single object name under an asset's directory, before extension. */
    public const OBJECT_NAME = 'original';

    public function canonical(AssetKind $kind, string $uuid, string $originalFilename): string
    {
        return $this->join($this->prefixFor($kind), $uuid, $originalFilename);
    }

    /**
     * Where bytes land while they are still unproven.
     *
     * Uploads are written here first so that a failure — a truncated stream, a
     * checksum that does not match, a client that disappears — never produces
     * an object at the address the catalogue will later call canonical.
     */
    public function staging(string $uuid, string $originalFilename): string
    {
        return $this->join(self::STAGING_PREFIX, $uuid, $originalFilename);
    }

    public function isStaging(string $key): bool
    {
        return str_starts_with($key, self::STAGING_PREFIX.'/');
    }

    /**
     * The top-level directory for a kind.
     *
     * Deliberately descriptive of the domain rather than of a provider: these
     * names appear in storage listings and backup scripts for years.
     */
    public function prefixFor(AssetKind $kind): string
    {
        return match ($kind) {
            AssetKind::AudioMaster => 'masters',
            AssetKind::AudioDerivative => 'derived',
            AssetKind::Preview => 'previews',
            AssetKind::Stem => 'stems',
            AssetKind::Artwork => 'artwork',
            AssetKind::Video => 'video',
            AssetKind::Lyrics => 'lyrics',
            AssetKind::Document, AssetKind::LicenseDocument => 'documents',
            AssetKind::DistributionExport => 'exports',
        };
    }

    private function join(string $prefix, string $uuid, string $originalFilename): string
    {
        $extension = OriginalFilename::extension($originalFilename);
        $object = self::OBJECT_NAME.($extension === null ? '' : '.'.$extension);

        return sprintf('%s/%s/%s', $prefix, $uuid, $object);
    }
}
