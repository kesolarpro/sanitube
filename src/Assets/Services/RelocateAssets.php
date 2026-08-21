<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use SaniTube\Assets\Models\Asset;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * Move a catalogue's bytes to another storage provider — slowly, provably,
 * and without ever holding a master in memory or deleting a source.
 *
 * This is the operator path from "everything on local disk" to "everything
 * in object storage": thousands of files, moved in batches an operator
 * sizes, resumable
 * because done is detected (the asset already names the target disk) rather
 * than remembered, and observable because every file reports its own
 * outcome.
 *
 * The order of operations is the whole safety story:
 *
 *   1. stream-copy source → target under the same key (never a whole file
 *      in PHP memory — the platform's oldest rule);
 *   2. read the *target* back and compare its checksum and size against
 *      what the asset has always claimed;
 *   3. only then switch the asset's `disk`, through the sanctioned
 *      relocation save (ADR-0022) — identity fields never move;
 *   4. never touch the source. Retiring local copies after a verified
 *      migration is a separate, explicit, human decision — this service
 *      has no delete call to make by design.
 *
 * A failed or mismatched copy removes its own unverified target object —
 * cleaning up a copy this run just wrote is housekeeping, not deletion of
 * anything of record — and reports the asset as failed, and the run
 * continues: one unreadable file must not strand the other four thousand.
 */
final readonly class RelocateAssets
{
    public function __construct(private StorageManager $storage) {}

    /**
     * @return array{moved: list<string>, already: int, failed: array<string, string>}
     */
    public function toProvider(string $target, int $limit, bool $dryRun = false): array
    {
        // Resolves or throws: a typoed provider name must stop the run
        // before the first byte moves, not after the four-thousandth.
        $destination = $this->storage->provider($target);

        $candidates = Asset::query()
            ->whereIn('status', ['STORED', 'VERIFIED'])
            ->where('disk', '!=', $target)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $moved = [];
        $failed = [];

        foreach ($candidates as $asset) {
            if ($dryRun) {
                $moved[] = $asset->uuid;

                continue;
            }

            try {
                $this->relocate($asset, $target);
                $moved[] = $asset->uuid;
            } catch (Throwable $exception) {
                $failed[$asset->uuid] = $exception->getMessage();
            }
        }

        $already = Asset::query()
            ->whereIn('status', ['STORED', 'VERIFIED'])
            ->where('disk', $target)
            ->count();

        return ['moved' => $moved, 'already' => $already, 'failed' => $failed];
    }

    private function relocate(Asset $asset, string $target): void
    {
        $source = $this->storage->provider($asset->disk);
        $destination = $this->storage->provider($target);

        $stream = $source->readStream($asset->path);

        try {
            $destination->putStream($asset->path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // The target is judged against what the asset has always claimed —
        // never against a fresh hash of the source, which would bless a copy
        // of already-corrupt bytes with a matching pair of wrong checksums.
        $checksum = $destination->checksum($asset->path);
        $size = $destination->size($asset->path);

        if (! hash_equals((string) $asset->sha256, $checksum) || $size !== $asset->byte_size) {
            // Our own unverified copy, written seconds ago: removing it is
            // housekeeping. The source and the record are untouched.
            $destination->delete($asset->path);

            throw new \RuntimeException(sprintf(
                'The copy did not verify (size %d vs %d): the target object was removed and the asset stays on [%s].',
                $size,
                (int) $asset->byte_size,
                $asset->disk,
            ));
        }

        $asset->sanctionRelocation();
        $asset->disk = $target;
        $asset->save();
    }
}
