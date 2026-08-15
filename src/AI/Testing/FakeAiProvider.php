<?php

declare(strict_types=1);

namespace SaniTube\AI\Testing;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\Contracts\AiProvider;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Storage\Testing\InMemoryStorageProvider;

/**
 * A provider that answers whatever a test needs it to.
 *
 * Shipped rather than left in the test directory, for the reason
 * {@see InMemoryStorageProvider} and
 * {@see FakeAudioAnalyzer} are: a contract with one
 * real implementation is a contract shaped around that implementation. This is
 * a third, written against the interface and checked by static analysis, and
 * it is what keeps `AiProvider` describing *asking a model*.
 *
 * It records the prompts it was given, because the thing most worth asserting
 * about an AI feature is not the answer — the fake made that up — but that the
 * instruction and the input were kept apart.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiPrompt> */
    public array $prompts = [];

    private bool $available = true;

    private ?AiCompletion $completion = null;

    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        $this->prompts[] = $prompt;

        if (! $this->available) {
            return AiCompletion::unavailable($this->name());
        }

        return $this->completion ?? new AiCompletion(
            provider: $this->name(),
            text: 'a plausible answer',
            model: 'fake-1',
            inputTokens: 12,
            outputTokens: 7,
        );
    }

    /**
     * Behave like an installation with no API key.
     */
    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function willReturn(AiCompletion $completion): self
    {
        $this->completion = $completion;

        return $this;
    }

    public function lastPrompt(): ?AiPrompt
    {
        return $this->prompts === [] ? null : $this->prompts[count($this->prompts) - 1];
    }
}
