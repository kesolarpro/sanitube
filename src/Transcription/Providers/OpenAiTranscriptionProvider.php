<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Providers;

use Illuminate\Support\Facades\Http;
use SaniTube\Foundation\Providers\ProviderCertification;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Transcription\Contracts\TranscriptionProvider;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\TranscriptionResult;
use SaniTube\Transcription\TranscriptSegmentResult;
use Throwable;

/**
 * Speech to text through OpenAI's audio transcription endpoint.
 *
 * **Written against the official OpenAPI specification**, not against a blog
 * post or recollection: `openai/openai-openapi`, the `/audio/transcriptions`
 * path. What that document establishes, and what this adapter therefore
 * assumes:
 *
 *   - `POST /v1/audio/transcriptions`, `multipart/form-data`
 *   - `file` and `model` required; `language`, `prompt`, `response_format`,
 *     `temperature` and `timestamp_granularities` optional
 *   - models include `whisper-1`, `gpt-4o-transcribe`, `gpt-4o-mini-transcribe`
 *   - `response_format` includes `json`, `verbose_json`, `diarized_json`
 *   - `verbose_json` returns `task`, `language`, `duration`, `text`, `words`
 *     and `segments`, where a segment carries `id`, `seek`, `start`, `end`,
 *     `text`, `tokens`, `temperature`, `avg_logprob`, `compression_ratio` and
 *     `no_speech_prob`. `start` and `end` are numbers **in seconds**.
 *
 * **What the specification does not say, and this adapter therefore does not
 * claim.** It carries no list of accepted audio formats and no maximum upload
 * size. Both are widely repeated in secondary sources and neither could be
 * verified from the official machine-readable contract available here, so the
 * size guard below is *configured policy* — a defence against paying to upload
 * a two-gigabyte master — and not a restatement of a documented API limit. The
 * service's own refusal remains the authority.
 *
 * The default model is `whisper-1` because that is the model whose
 * `verbose_json` segment contract the specification actually pins. The newer
 * models are configurable; whether they return the same segment structure was
 * not verifiable here, and defaulting to one on that assumption would be
 * exactly the guess this ticket was told not to make.
 */
final readonly class OpenAiTranscriptionProvider implements TranscriptionProvider
{
    /**
     * Bumped when the *meaning* of what this returns changes — a different
     * default model, a different response format, a different segment reading.
     * Not when the vendor changes something we do not depend on.
     */
    public const VERSION = '1';

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(private array $configuration) {}

    public function name(): string
    {
        return 'openai';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function isAvailable(): bool
    {
        return $this->certification()->mayAttempt();
    }

    /**
     * How far this provider has been proven here.
     *
     * Never `Certified` from configuration alone. A key being present says
     * somebody pasted a key; only a real call that succeeded says the account
     * exists, the model is permitted and the quota is not spent — and this
     * adapter does not make one in order to answer this question, because a
     * status check that costs money is a status check nobody runs.
     */
    public function certification(): ProviderCertification
    {
        return $this->key() === null || $this->baseUrl() === null
            ? ProviderCertification::NotConfigured
            : ProviderCertification::ConfiguredUncertified;
    }

    public function transcribe(string $localPath, ?string $languageHint = null): TranscriptionResult
    {
        if (! $this->isAvailable()) {
            throw TranscriptionFailed::providerUnavailable($this->name());
        }

        $size = @filesize($localPath);

        if ($size === false) {
            throw TranscriptionFailed::unreadableSource();
        }

        // Checked here rather than after the upload, because the cost of
        // finding out from the supplier is the whole transfer plus a refusal.
        $maximum = $this->maximumBytes();

        if ($maximum > 0 && $size > $maximum) {
            throw TranscriptionFailed::sourceTooLarge($this->name());
        }

        $handle = @fopen($localPath, 'rb');

        if ($handle === false) {
            throw TranscriptionFailed::unreadableSource();
        }

        try {
            $payload = $this->send($handle, basename($localPath), $languageHint);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->read($payload);
    }

    /**
     * @param  resource  $handle
     * @return array<array-key, mixed>
     */
    private function send($handle, string $filename, ?string $languageHint): array
    {
        $request = Http::baseUrl((string) $this->baseUrl())
            ->withToken((string) $this->key())
            ->timeout($this->timeout())
            // No automatic retry. A retried transcription is a second billed
            // call, and deciding whether to pay twice belongs to the job that
            // owns the work rather than to the transport.
            ->asMultipart()
            ->attach('file', $handle, $filename);

        $fields = [
            'model' => $this->model(),
            // The format the specification pins segments to. Asking for `json`
            // would return text with no timings at all, and inventing timings
            // afterwards is not something this platform does.
            'response_format' => 'verbose_json',
        ];

        // A hint, and sent as one. The API's `language` narrows the model's
        // search; it does not assert what the audio is, and the transcript
        // records the model's own answer beside it either way.
        if ($languageHint !== null && $languageHint !== '') {
            $fields['language'] = $languageHint;
        }

        try {
            $response = $request->post('/audio/transcriptions', $fields);
        } catch (Throwable $exception) {
            // Redacted, because the client quotes the request it sent and that
            // request carried an Authorization header. A failing call is
            // exactly the output somebody pastes into a support thread.
            throw TranscriptionFailed::callFailed($this->redact($exception->getMessage()));
        }

        if ($response->failed()) {
            // The body is not carried. A vendor's error routinely echoes the
            // request back, and there is no reading of that which is safe to
            // keep.
            throw TranscriptionFailed::callFailed($this->name());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw TranscriptionFailed::unreadableResponse($this->name());
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function read(array $payload): TranscriptionResult
    {
        $text = $payload['text'] ?? null;

        if (! is_string($text)) {
            throw TranscriptionFailed::unreadableResponse($this->name());
        }

        $language = $payload['language'] ?? null;
        $duration = $payload['duration'] ?? null;

        return new TranscriptionResult(
            text: $text,
            segments: $this->segments($payload['segments'] ?? null),
            languageCode: is_string($language) && $language !== '' ? $language : null,
            model: $this->model(),
            durationSeconds: is_numeric($duration) ? (int) round((float) $duration) : null,
        );
    }

    /**
     * @return list<TranscriptSegmentResult>
     */
    private function segments(mixed $segments): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $read = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = $segment['text'] ?? null;

            // A segment missing any of the three is skipped rather than
            // defaulted. A zero start is a real position, so coercing a
            // missing one would put text at the beginning of the recording.
            if (! is_numeric($start) || ! is_numeric($end) || ! is_string($text)) {
                continue;
            }

            // Seconds in the response, milliseconds in this platform. The
            // specification is explicit that these are seconds; storing them
            // unconverted would put every segment a thousand times too early.
            $read[] = new TranscriptSegmentResult(
                startMs: (int) round((float) $start * 1000),
                endMs: (int) round((float) $end * 1000),
                text: $text,
            );
        }

        return $read;
    }

    private function model(): string
    {
        $configured = $this->configuration['model'] ?? null;

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'whisper-1';
    }

    /**
     * Zero, and any unreadable value, mean no local ceiling — the supplier's
     * own refusal is then the only authority, which is a defensible choice for
     * an installation that knows its own files.
     */
    private function maximumBytes(): int
    {
        return max(0, (int) ($this->configuration['max_bytes'] ?? 0));
    }

    private function timeout(): int
    {
        return max(1, (int) ($this->configuration['timeout'] ?? 120));
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

    private function redact(string $message): string
    {
        return (new CredentialRedactor([$this->key()]))->redact($message);
    }
}
