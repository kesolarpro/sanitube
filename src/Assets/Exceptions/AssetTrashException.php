<?php

declare(strict_types=1);

namespace SaniTube\Assets\Exceptions;

use RuntimeException;

/**
 * A refusal to set an asset aside, or to bring it back.
 *
 * Every one carries a machine-readable reason. The English is for a developer
 * reading a log; the interface renders the code, because a reviewer who is
 * told "in use" needs to be shown *what* is using it, and a sentence composed
 * here cannot know.
 */
final class AssetTrashException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The catalogue points at it. Trashing would leave a track with no master
     * or a release with no cover, which is a broken catalogue rather than a
     * tidy one.
     */
    public static function inUse(string $by): self
    {
        return new self(sprintf('The asset is still referenced by %s.', $by), 'ASSET_IN_USE');
    }

    /**
     * The asset was set aside, and something is trying to make the catalogue
     * depend on it.
     *
     * TRASH-001. The mirror of {@see self::inUse()}: that one keeps a trashed
     * asset from breaking a track, this one keeps a trashed asset from
     * *becoming* one — which is the direction that ships rejected bytes to a
     * distributor.
     */
    public static function trashed(): self
    {
        return new self('The asset is in the trash and cannot be used by the catalogue.', 'ASSET_TRASHED');
    }

    public static function alreadyTrashed(): self
    {
        return new self('The asset is already in the trash.', 'ASSET_ALREADY_TRASHED');
    }

    public static function notTrashed(): self
    {
        return new self('The asset is not in the trash.', 'ASSET_NOT_TRASHED');
    }

    /**
     * Nothing that has not been read back may be set aside.
     *
     * A PENDING asset is an upload in flight, and trashing one would race the
     * write that is still happening. The staging sweep owns that case.
     */
    public static function notSettled(): self
    {
        return new self('The asset has no confirmed bytes yet.', 'ASSET_NOT_SETTLED');
    }
}
