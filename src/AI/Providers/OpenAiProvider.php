<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;

/**
 * OpenAI's chat completions API.
 *
 * The instruction becomes a system message and the input a user message,
 * rather than concatenating both into one string. The distinction is what the
 * model is trained on: text that arrives as user content is data, and text
 * that arrives as a system message is instruction. Since the input side is
 * frequently a filename or a description someone else wrote, collapsing them
 * would let catalogue data rewrite the instruction.
 */
final readonly class OpenAiProvider extends HttpAiProvider
{
    protected function send(AiPrompt $prompt): ?AiCompletion
    {
        $body = [
            'model' => $this->model($prompt),
            'max_completion_tokens' => $this->maxOutputTokens(),
            'messages' => [
                ['role' => 'system', 'content' => $prompt->instruction],
                ['role' => 'user', 'content' => $prompt->input],
            ],
        ];

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        if ($prompt->expectsJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->request()
            ->withToken((string) $this->key())
            ->post('/chat/completions', $body);

        if (! $response->successful()) {
            return AiCompletion::failed(
                $this->name(),
                $this->redact(sprintf('OpenAI answered %d: %s', $response->status(), $response->body())),
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $choices = $payload['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $text = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_string($text)) {
            return null;
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new AiCompletion(
            provider: $this->name(),
            text: $text,
            model: is_string($payload['model'] ?? null) ? $payload['model'] : $this->model($prompt),
            inputTokens: $this->integer($usage, 'prompt_tokens'),
            outputTokens: $this->integer($usage, 'completion_tokens'),
        );
    }
}
