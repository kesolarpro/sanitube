<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\Contracts\AiProvider;

/**
 * The default AI provider: does nothing, politely.
 *
 * With no API key configured — the state of every fresh install — AI features
 * report themselves unavailable and the rest of the platform carries on. An
 * absent OpenAI or Claude key must never stop someone cataloguing a track.
 */
final readonly class NullAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        return AiCompletion::unavailable($this->name());
    }
}
