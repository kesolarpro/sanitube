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

        /**
         * The shape the answer must arrive in.
         *
         * **Prefer this to `expectsJson` wherever a shape is known.**
         * `expectsJson` asks for valid JSON and nothing more: the model picks
         * the keys, and a model that picked different keys yesterday is a
         * silent data-quality change nobody gets told about. A schema names
         * the keys and both vendors can be made to hold to it.
         */
        public ?AiSchema $schema = null,
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
            schema: $this->schema,
        );
    }

    /**
     * Whether an answer that is not machine-readable is a failure.
     *
     * A schema implies it. Asking for a shape and then accepting a paragraph
     * would make the schema decoration.
     */
    public function requiresStructuredOutput(): bool
    {
        return $this->schema !== null || $this->expectsJson;
    }
}
