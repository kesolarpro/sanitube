<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

use SaniTube\Releases\Models\Release;

/**
 * A stable local name for "this release, to this distributor".
 *
 * The key is derived from the release's public uuid and the distributor's
 * name, so it is **the same value on every attempt** — including a retry after
 * a timeout, where SaniTube genuinely does not know whether the distributor
 * received the package. That is the only case idempotency exists for, and a
 * key regenerated per attempt would be no key at all.
 *
 * Derived rather than random for the same reason: a random key stored on the
 * row is lost if the row was never written, which is precisely the crash the
 * key is meant to survive. Deriving it means the same key can be recomputed
 * from scratch.
 *
 * The uuid is used, not the primary key: a key that embeds an internal id
 * leaks row counts to whichever distributor is on the other end.
 */
final readonly class DistributionIdempotencyKey
{
    public static function for(Release $release, string $provider): string
    {
        return hash('sha256', sprintf('release:%s|distributor:%s', $release->uuid, $provider));
    }
}
