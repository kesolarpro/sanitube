<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\AceStep;

/**
 * What ACE-Step's `POST /generate` says, in SaniTube's own words.
 *
 * The verified contract returns exactly three fields:
 *
 *     { "status": "success", "output_path": "...", "message": "..." }
 *
 * All three are here and nothing else is. An adapter that carried a field the
 * contract does not document would be an adapter written against a guess.
 *
 * `outputPath` is a path on the **engine's** host. It is an implementation
 * detail of that host: it is never persisted, never sent to a browser, never
 * stored as a SaniTube storage key, and it does not leave the worker.
 */
final readonly class AceStepOutput
{
    public function __construct(
        public string $status,
        public ?string $outputPath,
        public string $message = '',
    ) {}

    public function succeeded(): bool
    {
        return strtolower(trim($this->status)) === 'success';
    }
}
