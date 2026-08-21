<?php

declare(strict_types=1);

namespace SaniTube\Media\Worker;

use SaniTube\Storage\StorageManager;
use SaniTube\Worker\WorkerJobFailed;
use Throwable;

/**
 * Brings a stored object down to the worker's disk, and takes it away again.
 *
 * A local tool needs a local file. The worker has the same storage Core does,
 * so it streams the object itself — which is the point of handing it a key
 * rather than bytes: nothing crosses the Core-to-worker hop but a string.
 *
 * Streamed, never read into memory: a master is hundreds of megabytes and a
 * worker may well be a modest box.
 *
 * The temporary file is always removed, including when the work threw. A worker
 * that leaked one copy per probe would fill a disk in an afternoon.
 */
final readonly class StagedObject
{
    public function __construct(private StorageManager $storage) {}

    /**
     * @template T
     *
     * @param  callable(string): T  $work  handed a path that exists for its duration
     * @return T
     *
     * @throws WorkerJobFailed
     */
    public function withLocalCopy(string $storageKey, callable $work): mixed
    {
        $provider = $this->storage->default();

        if (! $provider->exists($storageKey)) {
            // Said plainly rather than discovered as an unreadable stream. Core
            // and the worker disagreeing about what is in storage is a
            // configuration fault, and it deserves its own word.
            throw WorkerJobFailed::because('OBJECT_NOT_IN_STORAGE');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'sanitube-worker-');

        if ($temporary === false) {
            throw WorkerJobFailed::because('WORKER_TEMP_UNAVAILABLE');
        }

        $source = null;
        $target = null;

        try {
            $source = $provider->readStream($storageKey);
            $target = fopen($temporary, 'wb');

            if ($target === false) {
                throw WorkerJobFailed::because('WORKER_TEMP_UNAVAILABLE');
            }

            stream_copy_to_stream($source, $target);

            if (is_resource($target)) {
                // Closed before the work reads it: a probe opening a handle
                // whose buffer has not been flushed reads a truncated file.
                fclose($target);
                $target = null;
            }

            return $work($temporary);
        } catch (WorkerJobFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            throw WorkerJobFailed::because('OBJECT_NOT_READABLE');
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }

            @unlink($temporary);
        }
    }
}
