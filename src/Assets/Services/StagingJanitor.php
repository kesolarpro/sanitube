<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Storage\StorageManager;

/**
 * Removes uploads that were started and never finished.
 *
 * A staging object outlives its upload whenever a client disappears, a process
 * is killed, or a checksum fails in a way that also loses the cleanup. Left
 * alone they accumulate quietly, and on a shared account they accumulate until
 * the quota stops something that matters.
 *
 * The safety property is the point of this class, not the deletion:
 *
 *   1. only keys under the reserved staging prefix are ever listed
 *   2. every key is re-checked against that prefix before it is deleted
 *   3. any key that some asset claims as its own path is skipped, whatever it
 *      looks like
 *   4. objects younger than the window are left alone, because a slow upload
 *      of a large master is not an abandoned one
 *
 * Rules 2 and 3 are redundant given rule 1. They are there anyway. This is the
 * only scheduled task in the platform whose bug would be "deleted a master",
 * and redundant checks are cheaper than that outcome.
 */
final readonly class StagingJanitor
{
    public function __construct(
        private StorageManager $storage,
        private AssetObjectKeys $keys,
    ) {}

    public function sweep(?string $provider = null, ?int $olderThanSeconds = null, bool $dryRun = false): StagingSweepReport
    {
        $providerName = $provider ?? $this->storage->defaultName();
        $store = $this->storage->provider($providerName);

        $ttl = $olderThanSeconds ?? (int) config('assets.staging.ttl_hours', 24) * 3600;
        $cutoff = Carbon::now()->subSeconds($ttl)->getTimestamp();

        $removed = [];
        $kept = 0;

        foreach ($store->files(AssetObjectKeys::STAGING_PREFIX) as $key) {
            if (! $this->keys->isStaging($key) || $this->isClaimedByAnAsset($key)) {
                $kept++;

                continue;
            }

            if ($store->lastModified($key) > $cutoff) {
                $kept++;

                continue;
            }

            if (! $dryRun) {
                $store->delete($key);
            }

            $removed[] = $key;
        }

        return new StagingSweepReport($providerName, $removed, $kept, $dryRun);
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
