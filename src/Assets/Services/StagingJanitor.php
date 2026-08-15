<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Storage\ObjectPrefix;
use SaniTube\Storage\StorageManager;

/**
 * Removes uploads that were started and never finished.
 *
 * A staging object outlives its upload whenever a client disappears, a process
 * is killed, or a checksum fails in a way that also loses the cleanup. Left
 * alone they accumulate quietly, and on a shared account they accumulate until
 * the quota stops something that matters.
 *
 * This is the only scheduled task in the platform whose bug would read
 * "deleted a master", so an object is removed only when **all five** of these
 * hold at once:
 *
 *   A. the provider returned it inside the exact staging scope
 *      ({@see ObjectPrefix} — the provider normalises the
 *      prefix and filters its own results, so this is not the first layer that
 *      trusts the scope, and it is re-checked here anyway)
 *   B. it parses as a key this platform could have written —
 *      `staging/{uuid}/original[.ext]`, exactly
 *   C. no asset of record claims that path
 *   D. it is older than the effective age threshold
 *   E. this is not a dry run
 *
 * A and C are unreachable given B and the prefix scoping. They are checked
 * regardless. Redundant conditions cost one comparison and one indexed query;
 * the outcome they guard against cannot be undone.
 *
 * Nothing here depends on staging living in a separate store. A physically
 * separate one would be a stronger boundary, but it would have to be
 * configured — and configured correctly — on every provider the platform
 * supports, on shared hosting, and in the installer. A boundary that is easy
 * to misconfigure on five surfaces is not obviously safer than one the code
 * enforces on all of them.
 */
final readonly class StagingJanitor
{
    /**
     * The youngest an object may be and still be swept automatically.
     *
     * A scheduled task must never be able to reach zero, however the
     * environment is configured: `SANITUBE_STAGING_TTL_HOURS=0` is a typo, and
     * without a floor it means "delete every upload currently in flight". The
     * clamp is applied to the value itself rather than validated at the edges,
     * so there is no path — config, cron, or a caller passing null — that
     * arrives under it. Only an explicit, warned CLI override can go lower.
     */
    public const MINIMUM_AGE_SECONDS = 3600;

    public function __construct(
        private StorageManager $storage,
        private AssetObjectKeys $keys,
    ) {}

    /**
     * @param  bool  $allowAgeBelowFloor  only ever true for a human at a terminal who asked for it
     */
    public function sweep(
        ?string $provider = null,
        ?int $olderThanSeconds = null,
        bool $dryRun = false,
        bool $allowAgeBelowFloor = false,
    ): StagingSweepReport {
        $providerName = $provider ?? $this->storage->defaultName();
        $store = $this->storage->provider($providerName);

        $age = $this->effectiveAge($olderThanSeconds, $allowAgeBelowFloor);
        $cutoff = Carbon::now()->subSeconds($age)->getTimestamp();

        $removed = [];
        $skipped = [];

        foreach ($store->files(AssetObjectKeys::STAGING_PREFIX) as $key) {
            // A — scope.
            if (! $this->keys->isStaging($key)) {
                $skipped[$key] = 'outside the staging prefix';

                continue;
            }

            // B — shape. `staging/foo/bar` satisfies A and is not something
            // SaniTube ever wrote, so it is left alone rather than deleted.
            if (! $this->keys->isValidStagingObjectKey($key)) {
                $skipped[$key] = 'not a staging object key this platform writes';

                continue;
            }

            // C — claimed by an asset.
            if ($this->isClaimedByAnAsset($key)) {
                $skipped[$key] = 'claimed by an asset';

                continue;
            }

            // D — age.
            if ($store->lastModified($key) > $cutoff) {
                $skipped[$key] = 'newer than the age threshold';

                continue;
            }

            // E — dry run.
            if (! $dryRun) {
                $store->delete($key);
            }

            $removed[] = $key;
        }

        return new StagingSweepReport($providerName, $removed, $skipped, $age, $dryRun);
    }

    /**
     * The age threshold actually applied, floor included.
     */
    private function effectiveAge(?int $olderThanSeconds, bool $allowAgeBelowFloor): int
    {
        $requested = $olderThanSeconds ?? (int) config('assets.staging.ttl_hours', 24) * 3600;

        if ($allowAgeBelowFloor) {
            return max(0, $requested);
        }

        return max($requested, self::MINIMUM_AGE_SECONDS);
    }

    /**
     * Whether any asset of record points at this object.
     *
     * Should never be true — canonical keys do not live under the staging
     * prefix — which is exactly why it is worth asking before deleting.
     */
    private function isClaimedByAnAsset(string $key): bool
    {
        return Asset::query()->where('path', $key)->exists();
    }
}
