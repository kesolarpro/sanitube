<?php

declare(strict_types=1);

namespace SaniTube\AI;

/**
 * A request to a language model, expressed in SaniTube's terms.
 *
 * `promptVersion` is not decoration: every AI-assisted change is written to
 * the audit log with the version that produced it, so a bad prompt can be
 * traced to the records it touched.
 */
final readonly class AiPrompt
{
    public function __construct(
        public string $instruction,
        public string $input = '',
        public string $promptVersion = 'v1',
        public ?string $model = null,
        public ?float $temperature = null,
        public bool $expectsJson = false,
    ) {}

    public function withModel(string $model): self
    {
        return new self(
            instruction: $this->instruction,
            input: $this->input,
            promptVersion: $this->promptVersion,
            model: $model,
            temperature: $this->temperature,
            expectsJson: $this->expectsJson,
        );
    }
}
