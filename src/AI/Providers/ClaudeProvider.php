<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\AiSchema;

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
 * **There is no `response_format` here, and that is the vendor's design rather
 * than a gap in this adapter.** Anthropic's way of guaranteeing a shape is a
 * tool: the schema is the tool's `input_schema`, the call is forced with
 * `tool_choice: {type: "tool", name}`, and the answer arrives as a `tool_use`
 * block whose `input` *is* the object. So `AiSchema` becomes a one-tool
 * request here and a `response_format` at OpenAI, and neither difference
 * leaves this directory.
 *
 * `strict: true` goes on the tool definition, which the official documentation
 * describes as the way to guarantee a tool call matches the schema exactly.
 *
 * Without a schema there is no JSON mode at all. Asking for JSON is then done
 * in the instruction, and `AiCompletion::json()` returns null rather than
 * throwing when a model answers with prose anyway — which is precisely why a
 * schema is the better request.
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

        if ($prompt->schema instanceof AiSchema) {
            $body['tools'] = [[
                'name' => $prompt->schema->name,
                'description' => $prompt->schema->description,
                'input_schema' => $prompt->schema->schema,
                'strict' => true,
            ]];

            // Forced, not suggested. With `auto` the model may answer in prose
            // and the schema becomes advice; `tool` is documented as making it
            // always use this particular tool.
            $body['tool_choice'] = ['type' => 'tool', 'name' => $prompt->schema->name];
        }

        $version = $this->configuration['version'] ?? null;

        $response = $this->request()
            ->withHeaders([
                'x-api-key' => (string) $this->key(),
                'anthropic-version' => is_string($version) && $version !== '' ? $version : '2023-06-01',
            ])
            ->post('/messages', $body);

        if (! $response->successful()) {
            return AiCompletion::failed($this->name(), $this->describeFailure($response->status()));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        // A schema request is answered in a tool block, never in prose, so
        // reading the text blocks would find nothing at all on exactly the
        // responses that carry the answer.
        $text = $prompt->schema instanceof AiSchema
            ? $this->toolInput($payload, $prompt->schema->name)
            : $this->firstTextBlock($payload);

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
     * The forced tool's arguments, as the JSON document they represent.
     *
     * Re-encoded rather than passed along as an array, so that a caller reads
     * one completion type whichever vendor answered — `AiCompletion::json()`
     * decodes it exactly as it decodes OpenAI's `json_schema` reply.
     *
     * **Matched by name.** A response can carry a tool block for something
     * else entirely — a server tool the account has enabled — and taking the
     * first one would read an unrelated call's arguments as this ticket's
     * suggestion.
     *
     * @param  array<string, mixed>  $payload
     */
    private function toolInput(array $payload, string $name): ?string
    {
        $content = $payload['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            if (($block['name'] ?? null) !== $name || ! is_array($block['input'] ?? null)) {
                continue;
            }

            $encoded = json_encode($block['input']);

            return is_string($encoded) ? $encoded : null;
        }

        return null;
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
