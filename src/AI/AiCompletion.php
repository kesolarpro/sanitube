<?php

declare(strict_types=1);

namespace SaniTube\AI;

/**
 * What a language model returned, plus everything needed to audit it.
 */
final readonly class AiCompletion
{
    public function __construct(
        public string $provider,
        public string $text,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public bool $refused = false,
    ) {}

    public static function unavailable(string $provider): self
    {
        return new self(provider: $provider, text: '', refused: true);
    }

    /**
     * Decode a JSON completion, returning null rather than throwing: a model
     * that ignored the requested format is an expected outcome, not an error.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->text, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isUsable(): bool
    {
        return ! $this->refused && trim($this->text) !== '';
    }
}
