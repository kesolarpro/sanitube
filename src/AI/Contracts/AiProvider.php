<?php

declare(strict_types=1);

namespace SaniTube\AI\Contracts;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;

/**
 * A large-language-model supplier.
 *
 * Controllers and domain services never call OpenAI or Claude directly. They
 * express an intent — classify this track, suggest metadata, translate this
 * description — and the AI module decides which provider serves it. That
 * indirection is what keeps an outage at one vendor from reaching the
 * catalogue, and what makes every AI-assisted decision auditable.
 */
interface AiProvider
{
    /**
     * Configuration name, e.g. "openai", "claude", "null".
     */
    public function name(): string;

    /**
     * False when the provider has no usable credentials. Callers must check
     * this rather than discovering it through an exception mid-workflow.
     */
    public function isAvailable(): bool;

    public function complete(AiPrompt $prompt): AiCompletion;
}
