<?php

declare(strict_types=1);

namespace SaniTube\AI\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\Contracts\AiProvider;
use SaniTube\Storage\CredentialRedactor;
use Throwable;

/**
 * What the two vendor adapters have in common.
 *
 * Not much, and that is the point: the request bodies and response shapes are
 * genuinely different, so only the parts that are the same live here — reading
 * configuration, refusing to call without a key, bounding the call, and making
 * sure an API key never reaches a log or a support thread.
 *
 * **No transport error is allowed to escape.** A vendor outage is an ordinary
 * condition for an optional feature; a queue worker that dies because OpenAI
 * returned a 502 has turned an assistant into a dependency.
 */
abstract readonly class HttpAiProvider implements AiProvider
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        private string $name,
        protected array $configuration,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->key() !== null && $this->baseUrl() !== null;
    }

    public function complete(AiPrompt $prompt): AiCompletion
    {
        if (! $this->isAvailable()) {
            return AiCompletion::unavailable($this->name());
        }

        try {
            $response = $this->send($prompt);
        } catch (Throwable $exception) {
            // Redacted, because the client's exception messages quote the
            // request — headers included — and a failing AI call is exactly
            // the output someone pastes into a support thread.
            return AiCompletion::failed($this->name(), $this->redact($exception->getMessage()));
        }

        if ($response === null) {
            return AiCompletion::failed($this->name(), 'The provider returned a response this adapter could not read.');
        }

        return $response;
    }

    abstract protected function send(AiPrompt $prompt): ?AiCompletion;

    protected function request(): PendingRequest
    {
        return Http::baseUrl((string) $this->baseUrl())
            ->timeout(max(1, (int) config('ai.timeout', 30)))
            // No automatic retry. A retried completion is a *second* billed
            // call that may return different text, and a ledger that recorded
            // one invocation for two charges is a ledger nobody can reconcile.
            // Retrying belongs to the job that owns the work.
            ->acceptJson();
    }

    protected function key(): ?string
    {
        $key = $this->configuration['key'] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    protected function baseUrl(): ?string
    {
        $url = $this->configuration['base_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : null;
    }

    protected function model(AiPrompt $prompt): string
    {
        if ($prompt->model !== null && $prompt->model !== '') {
            return $prompt->model;
        }

        $configured = $this->configuration['model'] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : 'default';
    }

    protected function maxOutputTokens(): int
    {
        return max(1, (int) config('ai.max_output_tokens', 1024));
    }

    protected function redact(string $message): string
    {
        return (new CredentialRedactor([$this->key()]))->redact($message);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function integer(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
