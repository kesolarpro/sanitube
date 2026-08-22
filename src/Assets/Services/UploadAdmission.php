<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use SaniTube\Assets\Enums\AssetKind;

/**
 * What a browser is allowed to hand this installation, and how large.
 *
 * **Admission, not conformance.** Whether an image is a usable release cover is
 * `ValidateArtwork`'s question, asked later on measured bytes. This answers a
 * narrower, earlier one: is this the sort of file that belongs under this kind
 * at all. A ZIP offered as a master is refused here rather than stored,
 * analysed and puzzled over for the rest of its life.
 *
 * **The media type is read from the bytes.** Never from the extension, never
 * from the `Content-Type` the browser sent — both are the uploader's word for
 * it, and a `.wav` holding a ZIP is a real thing.
 *
 * Both the list and the ceiling are configuration. An installation that
 * accepts something this one does not changes a setting, not a validator.
 */
final readonly class UploadAdmission
{
    /**
     * Kinds a person may upload from the interface.
     *
     * Deliberately short. A derivative, a preview and a stem are *produced* by
     * this platform from a master; letting somebody upload one directly would
     * create audio whose lineage is a claim rather than a record, and
     * `AssetKind::requiresParent()` exists precisely to stop that.
     *
     * @return list<AssetKind>
     */
    public function uploadableKinds(): array
    {
        return [AssetKind::AudioMaster, AssetKind::Artwork];
    }

    public function allows(AssetKind $kind): bool
    {
        return in_array($kind, $this->uploadableKinds(), true);
    }

    /**
     * @return list<string> empty means any type is accepted for this kind
     */
    public function acceptedTypes(AssetKind $kind): array
    {
        $configured = config('assets.accepted_upload_types.'.$kind->value, []);

        if (! is_array($configured)) {
            return [];
        }

        $types = [];

        foreach ($configured as $type) {
            if (is_string($type) && trim($type) !== '') {
                $types[] = strtolower(trim($type));
            }
        }

        return array_values(array_unique($types));
    }

    public function acceptsType(AssetKind $kind, ?string $mimeType): bool
    {
        $accepted = $this->acceptedTypes($kind);

        if ($accepted === []) {
            // An empty list is "any type", not "no type". The alternative would
            // make an installation that cleared the list unable to upload
            // anything, which is never what clearing a list expresses.
            return true;
        }

        return $mimeType !== null && in_array(strtolower($mimeType), $accepted, true);
    }

    public function maximumBytes(AssetKind $kind): int
    {
        return max(0, (int) config('assets.max_upload_bytes.'.$kind->value, 0));
    }

    /**
     * The ceiling that actually binds, given how the bytes will travel.
     *
     * On the **direct** path the bytes never enter PHP, so PHP's limits are
     * not the binding ones and the configured maximum is the truth. On the
     * **relayed** path PHP's limit usually *is* the truth, and on shared
     * hosting it is far below anything an operator set here.
     *
     * This is the rule both upload screens need, which is why it lives here
     * rather than inline in one of them: UPL-004 found the catalogue import
     * screen promising two gigabytes on a host that drops a five-megabyte
     * file, because the rule existed on the single-file screen only. A rule
     * kept in one place cannot be present on one screen and absent on
     * another.
     */
    public function effectiveMaximumBytes(AssetKind $kind, bool $direct): int
    {
        $configured = $this->maximumBytes($kind);
        $php = $this->phpPostLimitBytes();

        if ($direct || $php <= 0) {
            return $configured;
        }

        return $configured > 0 ? min($configured, $php) : $php;
    }

    /**
     * Whether PHP, not this application's configuration, is what will refuse
     * a large file — the fact a person needs to be told when the number on
     * screen is smaller than the one an operator set.
     */
    public function limitedByPhp(AssetKind $kind, bool $direct): bool
    {
        if ($direct) {
            return false;
        }

        $php = $this->phpPostLimitBytes();
        $configured = $this->maximumBytes($kind);

        return $php > 0 && ($configured <= 0 || $php < $configured);
    }

    /**
     * What PHP itself will accept, which on shared hosting is usually lower
     * than anything configured above.
     *
     * Reported rather than assumed, because an operator who has raised
     * `SANITUBE_MAX_MASTER_BYTES` to two gigabytes and left `post_max_size` at
     * eight megabytes has an installation that refuses every master with a
     * message about neither.
     */
    public function phpPostLimitBytes(): int
    {
        $limits = array_filter([
            self::toBytes((string) ini_get('upload_max_filesize')),
            self::toBytes((string) ini_get('post_max_size')),
        ], static fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * PHP's shorthand notation — `8M`, `2G` — as bytes.
     */
    private static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
