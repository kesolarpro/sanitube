<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker;

use SaniTube\MusicGeneration\AceStep\AceStepEngine;
use SaniTube\MusicGeneration\AceStep\AceStepUnavailable;
use SaniTube\MusicGeneration\AceStep\ContainedPath;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * Everything the generation worker does, in one place.
 *
 * The worker exists because of one line in ACE-Step's verified contract: it
 * answers with `output_path`, a path on **its own** filesystem. SaniTube Core
 * runs on cPanel, on a VPS, on whatever an operator has; the engine runs where
 * a GPU is. Making Core able to open that path would mean making Core and the
 * GPU share a filesystem, which is a deployment SaniTube may not require.
 *
 * So the path stops here. The worker opens the file, hands the bytes to the
 * storage both sides already share, and tells Core a **storage key** — which is
 * a thing Core already knows how to ingest, from any host, with no assumption
 * about where the engine lives.
 *
 * The order is fixed, and each step exists because the one before it can lie:
 *
 *   1. **choose** the output filename, so what comes back is something this
 *      worker can reason about;
 *   2. **render**, through the engine seam;
 *   3. **contain** — {@see ContainedPath}, resolved before compared;
 *   4. **validate** the file itself: regular, non-empty, within the size limit,
 *      an extension this worker accepts;
 *   5. **stage** it into shared storage, streamed, never through PHP memory;
 *   6. **clean up**, whatever happened, including when a step above threw.
 *
 * Nothing here interpolates a shell. The engine contract is HTTP and the file
 * is opened by path with PHP's own functions, so there is no command line for a
 * filename to escape from.
 */
final readonly class RunGenerationOnWorker
{
    public function __construct(
        private AceStepEngine $engine,
        private StorageManager $storage,
    ) {}

    /**
     * @return array{key: string, bytes: int, checksum: string}
     *
     * @throws WorkerGenerationFailed
     */
    public function handle(GenerationRequest $request, string $reference): array
    {
        $root = $this->outputRoot();

        if ($root === null) {
            throw WorkerGenerationFailed::because('WORKER_OUTPUT_ROOT_NOT_CONFIGURED');
        }

        // Chosen here, and never from the caller. `$reference` is a uuid this
        // worker was handed; it is sanitised to hex regardless, because a
        // filename is the one place a caller-supplied string touches a
        // filesystem and "it is always a uuid" is a property of today's caller.
        $outputPath = $root.DIRECTORY_SEPARATOR.'sanitube-'.$this->safeReference($reference).'.wav';

        try {
            $output = $this->engine->render($request, $outputPath);
        } catch (AceStepUnavailable $exception) {
            throw WorkerGenerationFailed::because($exception->reason);
        }

        if (! $output->succeeded()) {
            // The engine's own `message` is deliberately not carried: it is
            // `f"Error generating audio: {str(e)}"` around a Python traceback.
            $this->discard($outputPath);

            throw WorkerGenerationFailed::because('ENGINE_REPORTED_FAILURE');
        }

        $rejection = ContainedPath::reject($root, (string) $output->outputPath);

        if ($rejection !== null) {
            // Deliberately not cleaned up by the returned path -- that is the
            // path being refused. The one this worker asked for is cleaned
            // instead, in case the engine wrote both.
            $this->discard($outputPath);

            throw WorkerGenerationFailed::because($rejection);
        }

        /** @var string $resolved */
        $resolved = ContainedPath::within($root, (string) $output->outputPath);

        try {
            return $this->stage($resolved, $reference);
        } finally {
            // Whatever happened. A worker that leaves rendered masters behind
            // fills a GPU host's disk in a week.
            $this->discard($resolved);
            $this->discard($outputPath);
        }
    }

    /**
     * @return array{key: string, bytes: int, checksum: string}
     *
     * @throws WorkerGenerationFailed
     */
    private function stage(string $path, string $reference): array
    {
        $bytes = filesize($path);

        if ($bytes === false || $bytes === 0) {
            throw WorkerGenerationFailed::because('OUTPUT_EMPTY');
        }

        $limit = $this->maximumBytes();

        if ($limit > 0 && $bytes > $limit) {
            // Refused before it is read, not after. A limit checked while
            // streaming is a limit that has already cost the transfer.
            throw WorkerGenerationFailed::because('OUTPUT_TOO_LARGE');
        }

        if (! in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), $this->allowedExtensions(), true)) {
            throw WorkerGenerationFailed::because('OUTPUT_UNEXPECTED_TYPE');
        }

        $key = $this->stagingPrefix().'/'.$this->safeReference($reference).'.'.strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        try {
            // `putFile`, not `put`. A rendered master must never have to fit in
            // PHP's memory -- on a shared host least of all, and the worker may
            // well be one.
            $stored = $this->storage->default()->putFile($key, $path);
        } catch (Throwable) {
            throw WorkerGenerationFailed::because('STAGING_FAILED');
        }

        return [
            'key' => $stored->key,
            'bytes' => $stored->size,
            // Computed from the staged object rather than the local file: what
            // Core will ingest is the thing that should be identified, and a
            // checksum of the local copy would only prove the upload started.
            'checksum' => $this->checksum($stored->key),
        ];
    }

    private function checksum(string $key): string
    {
        try {
            return $this->storage->default()->checksum($key);
        } catch (Throwable) {
            // Evidence, not identity. Core verifies the bytes it ingests for
            // itself; an absent checksum here must not lose a finished
            // generation.
            return '';
        }
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * A filename component that cannot be anything but one.
     *
     * Hex only. Not an escape and not a validation -- a transformation, which
     * is the only one of the three that cannot be got wrong by a later edit.
     */
    private function safeReference(string $reference): string
    {
        $safe = preg_replace('/[^a-f0-9]/i', '', $reference);

        return $safe === null || $safe === '' ? bin2hex(random_bytes(8)) : substr($safe, 0, 64);
    }

    private function outputRoot(): ?string
    {
        $configured = config('generation.acestep.output_root');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $resolved = realpath($configured);

        return $resolved === false || ! is_dir($resolved) ? null : $resolved;
    }

    private function maximumBytes(): int
    {
        return max(0, (int) config('generation.worker.max_output_bytes', 0));
    }

    /**
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        $configured = config('generation.worker.allowed_extensions');

        if (! is_array($configured) || $configured === []) {
            return ['wav', 'flac', 'mp3'];
        }

        $allowed = [];

        foreach ($configured as $extension) {
            if (is_string($extension) && trim($extension) !== '') {
                $allowed[] = strtolower(trim($extension));
            }
        }

        return $allowed === [] ? ['wav', 'flac', 'mp3'] : $allowed;
    }

    private function stagingPrefix(): string
    {
        $configured = config('generation.worker.staging_prefix');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured, '/')
            : 'staging/generation';
    }
}
