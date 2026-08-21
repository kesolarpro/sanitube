<?php

declare(strict_types=1);

namespace SaniTube\Storage\Enums;

/**
 * The parts of a storage provider an operator may configure.
 *
 * **This list is a security boundary, not a convenience.** Each case is a key
 * on a Laravel filesystem disk, and the settings screen builds its fields from
 * whatever `config/storage.php` declares — so if the vocabulary were open, a
 * provider entry could name `url` and the screen would cheerfully offer to set
 * it. That one key makes Laravel serve a permanent, unexpiring public address
 * for every object on the disk, which is the exact opposite of how SaniTube
 * reaches audio: an expiring signed link, minted per request. A configuration
 * file is not the place that decision gets to be made, so `url` is not here
 * and anything not here is dropped rather than rendered.
 *
 * The value is the disk key. That is deliberate: a field named `bucket` reads
 * `filesystems.disks.<disk>.bucket`, so the mapping needs no second table that
 * could disagree with this one.
 */
enum StorageField: string
{
    /** The access key id. Half of a credential pair. */
    case Key = 'key';

    /** The secret access key. */
    case Secret = 'secret';

    /** The bucket the masters live in. */
    case Bucket = 'bucket';

    /**
     * The service address.
     *
     * An address, not a credential — and one an operator has to be able to
     * read. R2 and B2 are addressed per account, so a deployment pointed at
     * the wrong account is invisible from every other screen. Amazon derives
     * its endpoint from the region and therefore declares none.
     */
    case Endpoint = 'endpoint';

    /** The region, or `auto` for the services that do not use one. */
    case Region = 'region';

    /**
     * Whether the browser is told only "configured" or "not configured".
     *
     * The credential pair and the bucket are held secret; the bucket because
     * naming it tells an attacker exactly what to try to reach. The address
     * and the region are published, for the same reason an AI provider's base
     * URL is: a misdirected installation cannot be diagnosed from a screen
     * that refuses to say where it is pointed.
     */
    public function isSecret(): bool
    {
        return match ($this) {
            self::Key, self::Secret, self::Bucket => true,
            self::Endpoint, self::Region => false,
        };
    }

    /**
     * Validation for the settings form.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Key, self::Secret, self::Bucket => ['string', 'max:255'],
            self::Endpoint => ['url', 'max:255'],
            self::Region => ['string', 'max:64'],
        };
    }
}
