<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use SaniTube\Localization\ContentLanguage;

/**
 * What the operator asked for, in SaniTube's own vocabulary.
 *
 * Adapters translate this into whatever shape their provider expects. The
 * reverse never happens: a provider's request format must not leak inward.
 */
final readonly class GenerationRequest
{
    /**
     * @param  array<string, scalar|null>  $parameters  provider-specific extras, deliberately untyped
     */
    public function __construct(
        public string $prompt,
        public ?string $stylePrompt = null,
        public ?string $lyrics = null,
        public bool $instrumental = false,
        public ?ContentLanguage $language = null,
        public ?string $model = null,
        public array $parameters = [],
    ) {}

    public function withModel(string $model): self
    {
        return new self(
            prompt: $this->prompt,
            stylePrompt: $this->stylePrompt,
            lyrics: $this->lyrics,
            instrumental: $this->instrumental,
            language: $this->language,
            model: $model,
            parameters: $this->parameters,
        );
    }

    /**
     * Effective language of the generated work: an instrumental has no
     * linguistic content, whatever prompt produced it.
     */
    public function effectiveLanguage(): ContentLanguage
    {
        if ($this->instrumental) {
            return ContentLanguage::instrumental();
        }

        return $this->language ?? ContentLanguage::unknown();
    }
}
