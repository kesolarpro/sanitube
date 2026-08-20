<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\AiSchema;

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

        // A schema wins over a bare JSON request. `json_object` guarantees the
        // answer parses and nothing about what is in it; `json_schema` with
        // `strict` guarantees the keys, per the official OpenAPI
        // specification's `ResponseFormatJsonSchema`.
        if ($prompt->schema instanceof AiSchema) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $prompt->schema->name,
                    'description' => $prompt->schema->description,
                    'schema' => $prompt->schema->schema,
                    'strict' => true,
                ],
            ];
        } elseif ($prompt->expectsJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->request()
            ->withToken((string) $this->key())
            ->post('/chat/completions', $body);

        if (! $response->successful()) {
            return AiCompletion::failed($this->name(), $this->describeFailure($response->status()));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $choices = $payload['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        $text = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_string($text) || trim($text) === '') {
            // Includes the case the specification calls a refusal: a strict
            // schema request the model declined, where `content` is null. An
            // empty string is not an empty answer to be stored, it is no
            // answer -- and reading it as one would write a suggestion nobody
            // made.
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
