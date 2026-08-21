<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker;

use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\Worker\Contracts\WorkerJobHandler;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;

/**
 * Music generation, as a worker capability.
 *
 * The first handler on the general boundary, and the reason the boundary
 * exists: an engine that needs a GPU cannot run where Core does. Registered by
 * the MusicGeneration module, so the Worker module never learns what generation
 * is.
 *
 * **`rules()` is the security boundary and it is short on purpose.** There is
 * no field here for a checkpoint, a device, an output path, a model, a step
 * count or a guidance scale, because every one of those is a decision this
 * worker makes from its own configuration. A request that could set them would
 * be a request that can read a file, choose a GPU, or make one generation cost
 * fifty times another.
 */
final readonly class MusicGenerationJobHandler implements WorkerJobHandler
{
    public function __construct(private RunGenerationOnWorker $worker) {}

    public function capability(): WorkerCapability
    {
        return WorkerCapability::MusicGeneration;
    }

    /**
     * Cheap, as a handshake requires: two configuration reads and a directory
     * check. Never a call to the engine — a handshake that woke a GPU to prove
     * it could would be a handshake nobody dares poll.
     */
    public function isRunnable(): bool
    {
        $endpoint = config('generation.acestep.endpoint');
        $root = config('generation.acestep.output_root');

        return is_string($endpoint) && trim($endpoint) !== ''
            && is_string($root) && trim($root) !== ''
            && is_dir($root);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64'],
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'style_prompt' => ['nullable', 'string', 'max:2000'],
            'lyrics' => ['nullable', 'string', 'max:20000'],
            'instrumental' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', 'max:8'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $language = $this->text($payload, 'language');

        try {
            $staged = $this->worker->handle(
                new GenerationRequest(
                    prompt: (string) ($payload['prompt'] ?? ''),
                    stylePrompt: $this->text($payload, 'style_prompt'),
                    lyrics: $this->text($payload, 'lyrics'),
                    instrumental: (bool) ($payload['instrumental'] ?? false),
                    language: $language === null ? null : ContentLanguage::fromCode($language),
                ),
                (string) ($payload['reference'] ?? ''),
            );
        } catch (WorkerGenerationFailed $failure) {
            // Re-thrown as the boundary's own type so the controller stays
            // generic. The code travels unchanged; it is from a closed set.
            throw WorkerJobFailed::because($failure->reason);
        }

        return [
            // A storage key, which Core already knows how to ingest from any
            // host. Never the engine's `output_path`.
            'storage_key' => $staged['key'],
            'bytes' => $staged['bytes'],
            'checksum' => $staged['checksum'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
