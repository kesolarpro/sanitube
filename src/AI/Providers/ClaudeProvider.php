<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;

/**
 * Anthropic's messages API.
 *
 * Different enough from OpenAI to be worth stating: the system instruction is
 * a top-level field rather than a message, `max_tokens` is required rather
 * than optional, the version is negotiated by header, and the answer arrives
 * as a list of content blocks instead of a single string. Two adapters exist
 * so those differences stay here instead of leaking into the domain — and so
 * `AiProvider` describes *asking a model*, not one vendor's request body.
 *
 * There is no JSON response mode. Asking for JSON is done in the instruction,
 * and `AiCompletion::json()` returns null rather than throwing when a model
 * answers with prose anyway.
 */
final readonly class ClaudeProvider extends HttpAiProvider
{
    protected function send(AiPrompt $prompt): ?AiCompletion
    {
        $body = [
            'model' => $this->model($prompt),
            'max_tokens' => $this->maxOutputTokens(),
            'system' => $prompt->instruction,
            'messages' => [
                ['role' => 'user', 'content' => $prompt->input],
            ],
        ];

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        $version = $this->configuration['version'] ?? null;

        $response = $this->request()
            ->withHeaders([
                'x-api-key' => (string) $this->key(),
                'anthropic-version' => is_string($version) && $version !== '' ? $version : '2023-06-01',
            ])
            ->post('/messages', $body);

        if (! $response->successful()) {
            return AiCompletion::failed(
                $this->name(),
                $this->redact(sprintf('Claude answered %d: %s', $response->status(), $response->body())),
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $text = $this->firstTextBlock($payload);

        if ($text === null) {
            return null;
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new AiCompletion(
            provider: $this->name(),
            text: $text,
            model: is_string($payload['model'] ?? null) ? $payload['model'] : $this->model($prompt),
            inputTokens: $this->integer($usage, 'input_tokens'),
            outputTokens: $this->integer($usage, 'output_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstTextBlock(array $payload): ?string
    {
        $content = $payload['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            // A response can open with a non-text block — a tool use, a
            // thinking block — and taking element zero blindly would return
            // nothing on exactly the responses that carry the most.
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        return null;
    }
}
