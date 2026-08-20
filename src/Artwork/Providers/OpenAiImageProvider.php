<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Providers;

use Illuminate\Support\Facades\Http;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageCapabilities;
use SaniTube\Artwork\ImageRequest;
use SaniTube\Artwork\ImageSize;
use Throwable;

/**
 * OpenAI's image generation endpoint.
 *
 * Written against the published `openai/openai-openapi` specification — the
 * `createImage` operation at `POST /images/generations`, its
 * `CreateImageRequest` schema and the `ImagesResponse` it returns. Nothing here
 * is guessed, per §5 and ADR-0018: an adapter is written only against a contract
 * its supplier has published.
 *
 * Two details from that specification shape this adapter, and both are the kind
 * of thing an assumption gets wrong:
 *
 * - **`response_format` is not a GPT-image parameter.** The specification says
 *   it applies to `dall-e-2` and `dall-e-3`, and that the GPT image models
 *   "always return base64-encoded images". Sending it to a GPT image model is
 *   sending a parameter that model does not take.
 * - **A `url` is only ever produced by the dall-e models, and is valid for 60
 *   minutes.** That is a bearer credential with an expiry. This adapter never
 *   returns one: it asks for base64, and a response carrying only a URL is
 *   treated as unreadable rather than followed. Nothing downstream should hold
 *   a provider address, and nothing here will fetch one on the platform's
 *   behalf.
 *
 * **Sizes are configured, not inferred.** The specification lists different
 * allowed sizes per model and describes arbitrary sizes for one family with
 * divisibility and aspect constraints. Working out from a model name what a
 * given account may request is exactly the guessing that is forbidden, so what
 * this installation may ask for is declared in `config/artwork.php` and
 * corrected there when the vendor changes it.
 */
final readonly class OpenAiImageProvider implements ImageProvider
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(private array $configuration) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->key() !== null && $this->baseUrl() !== null;
    }

    public function capabilities(): ImageCapabilities
    {
        $sizes = [];

        foreach ((array) ($this->configuration['sizes'] ?? []) as $value) {
            $size = is_string($value) ? ImageSize::parse($value) : null;

            if ($size instanceof ImageSize) {
                $sizes[] = $size;
            }
        }

        $formats = [];

        foreach ((array) ($this->configuration['output_mime_types'] ?? []) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $formats[] = strtolower(trim($value));
            }
        }

        return new ImageCapabilities($sizes, $formats);
    }

    public function generate(ImageRequest $request): ?GeneratedImage
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $body = array_filter([
            'model' => $this->model(),
            'prompt' => $request->prompt,
            'n' => 1,
            'size' => (string) $request->size,
            'quality' => $request->quality,
            'background' => $request->background,
            'output_format' => $this->outputFormat(),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = Http::baseUrl((string) $this->baseUrl())
                ->timeout(max(1, (int) ($this->configuration['timeout'] ?? 120)))
                // No automatic retry. A retried generation is a second billed
                // image; retrying belongs to the job that owns the work.
                ->withToken((string) $this->key())
                ->acceptJson()
                ->post('/images/generations', $body);
        } catch (Throwable) {
            // The message is not captured, and redacting it would not make it
            // safe to keep: redaction masks this installation's credential and
            // cannot mask the prompt the client quotes back inside the message.
            // Only the fact of failure leaves this method, which is what the
            // caller acts on -- GenerateArtwork turns it into a refusal code.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return $this->firstImage($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstImage(array $payload): ?GeneratedImage
    {
        $data = $payload['data'] ?? null;
        $first = is_array($data) ? ($data[0] ?? null) : null;

        if (! is_array($first)) {
            return null;
        }

        $encoded = $first['b64_json'] ?? null;

        // A response carrying only a `url` is not followed. It is a link valid
        // for an hour -- a bearer credential -- and no adapter here fetches an
        // address a provider handed it.
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return new GeneratedImage(
            bytes: $bytes,
            // What the provider says it produced. Not trusted: the stored asset
            // is measured like any other, and the measurement is what the
            // release validator judges.
            mimeType: $this->mimeTypeFor($payload),
            model: is_string($payload['model'] ?? null) ? $payload['model'] : $this->model(),
            revisedPrompt: is_string($first['revised_prompt'] ?? null) ? $first['revised_prompt'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mimeTypeFor(array $payload): string
    {
        $format = $payload['output_format'] ?? $this->outputFormat();

        return match (is_string($format) ? strtolower($format) : 'png') {
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function outputFormat(): string
    {
        $format = $this->configuration['output_format'] ?? null;

        return is_string($format) && trim($format) !== '' ? strtolower(trim($format)) : 'png';
    }

    private function model(): string
    {
        $model = $this->configuration['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : 'gpt-image-1';
    }

    private function key(): ?string
    {
        $key = $this->configuration['key'] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function baseUrl(): ?string
    {
        $url = $this->configuration['base_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : null;
    }
}
