<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use SaniTube\Ingestion\Services\InboxDeposit;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;

/**
 * What the catalogue import screen is allowed to promise, and what is already
 * waiting.
 *
 * **The waiting list is read from storage, not from the browser.** This is the
 * whole reason the import survives a refresh. A screen that kept its queue in
 * JavaScript would lose nine hundred deposits to one accidental reload, and
 * there would be no way to find out what had actually landed short of reading
 * a bucket by hand. The objects in the inbox *are* the durable state; nothing
 * needed inventing to make that true.
 *
 * What it cannot recover is the part no server can: a file that was picked and
 * never uploaded exists only in the browser, and a reload loses the handle to
 * it. The screen says so rather than implying a resume that cannot happen.
 *
 * **`direct` is a capability, never a provider name.** Asked of the configured
 * provider through `instanceof SupportsDirectUpload`, so a driver added later
 * is on the fast path the day it can be, without a name appearing in a
 * condition here.
 */
final readonly class ImportScreenQuery
{
    public function __construct(
        private StorageManager $storage,
        private InboxDeposit $inbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $direct = $this->storage->default() instanceof SupportsDirectUpload;

        return [
            'direct' => $direct,
            'accepted_types' => $this->inbox->acceptedTypes(),
            'maximum_bytes' => $this->inbox->maximumBytes(),

            // UPL-004. What will *actually* refuse a file, which on a relayed
            // deposit is PHP's own limit and not the number an operator set
            // here. Promising the configured ceiling is how a real 4.7 MB MP3
            // came to be refused in production with a message about neither
            // number; the screen now states the binding one and says whose it
            // is.
            'effective_maximum_bytes' => $this->inbox->effectiveMaximumBytes($direct),
            'limited_by_php' => $this->inbox->limitedByPhp($direct),
            'php_post_limit_bytes' => $this->inbox->phpPostLimitBytes(),
            'per_request' => $this->inbox->perRequestLimit(),
            'concurrency' => $this->inbox->concurrency(),
            'max_batch' => max(1, (int) config('ingestion.max_batch', 500)),
            'waiting' => $this->waiting(),
        ];
    }

    /**
     * Everything sitting in the inbox, by key.
     *
     * A failure to list is reported as an empty inbox *plus* a flag, never as
     * an empty inbox alone: "nothing is waiting" and "we could not ask" lead a
     * person to opposite actions, and only one of them is fixed by uploading
     * the files again.
     *
     * @return array{available: bool, files: list<array<string, mixed>>}
     */
    private function waiting(): array
    {
        $store = $this->storage->default();

        try {
            $keys = $store->files($this->inbox->prefix().'/');
        } catch (StorageOperationFailed) {
            return ['available' => false, 'files' => []];
        }

        $files = [];

        foreach ($keys as $key) {
            $files[] = [
                'reference' => $key,
                // The name a person recognises. `ManualUploadReader` reads the
                // same basename, so what is shown here is what the candidate
                // will be called.
                'name' => basename($key),
                'byte_size' => $this->sizeOf($store, $key),
            ];
        }

        // By name, so the same import listed twice reads the same way both
        // times. Storage listings carry no order worth relying on.
        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return ['available' => true, 'files' => $files];
    }

    private function sizeOf(StorageProvider $store, string $key): ?int
    {
        try {
            return $store->size($key);
        } catch (StorageOperationFailed) {
            // An object that lists but will not measure is reported without a
            // size rather than dropped: it is still going to be imported, and
            // hiding it would make the count on screen disagree with the batch.
            return null;
        }
    }
}
