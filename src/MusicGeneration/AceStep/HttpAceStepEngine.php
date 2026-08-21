<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\AceStep;

use Illuminate\Http\Client\Factory as HttpFactory;
use SaniTube\MusicGeneration\GenerationRequest;
use Throwable;

/**
 * Talks to a local ACE-Step server over its published contract.
 *
 * Written against `infer-api.py` in ACE-Step's own repository and nothing else.
 * The request body below is that file's `ACEStepInput`, field for field; the
 * response is its `ACEStepOutput`. No field is invented and none is guessed.
 *
 * **The engine's own reference server is a reference server**, and this adapter
 * is written knowing it. Three facts from reading it:
 *
 *   1. `initialize_pipeline()` is called *inside* the request handler, so the
 *      model is loaded per request. That is why the timeout is generous and
 *      configurable rather than a number in the code.
 *   2. `checkpoint_path` and `device_id` come from the **client**. They are
 *      therefore chosen here, from this worker's configuration, and no part of
 *      a `GenerationRequest` can reach them. A prompt that could pick the
 *      checkpoint would be a prompt that can read a file.
 *   3. `output_path` is client-chosen too, and defaults to a filename the
 *      server invents. The worker always supplies one, so that what comes back
 *      is something it can reason about — and checks it anyway.
 *
 * Nothing here shells out. The contract is HTTP, and the whole request is a
 * JSON body built from typed values, so there is no string for a shell to
 * interpret and no command to interpolate into.
 */
final readonly class HttpAceStepEngine implements AceStepEngine
{
    /**
     * @param  array<string, mixed>  $settings  this worker's own ACE-Step configuration
     */
    public function __construct(
        private HttpFactory $http,
        private array $settings = [],
    ) {}

    public function render(GenerationRequest $request, string $outputPath): AceStepOutput
    {
        try {
            $response = $this->http
                ->timeout($this->timeout())
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint().'/generate', $this->body($request, $outputPath));
        } catch (Throwable) {
            // The message is not carried. A client exception quotes the request
            // that produced it, which on a configured deployment includes the
            // engine's address.
            throw AceStepUnavailable::unreachable();
        }

        if (! $response->successful()) {
            throw AceStepUnavailable::refused();
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['status'] ?? null)) {
            throw AceStepUnavailable::malformed();
        }

        $path = $payload['output_path'] ?? null;

        return new AceStepOutput(
            status: $payload['status'],
            outputPath: is_string($path) ? $path : null,
            message: is_string($payload['message'] ?? null) ? $payload['message'] : '',
        );
    }

    public function isHealthy(): bool
    {
        try {
            // A short timeout of its own: this is asked on a page load, and a
            // health check that inherits a generation timeout would hang a
            // screen for minutes on an engine that is simply switched off.
            return $this->http
                ->timeout($this->healthTimeout())
                ->acceptJson()
                ->get($this->endpoint().'/health')
                ->successful();
        } catch (Throwable) {
            // Never throws. A studio screen that cannot generate must still
            // render.
            return false;
        }
    }

    /**
     * The published request body, built from typed values.
     *
     * Every generation parameter comes from **this worker's configuration**,
     * not from the caller. A `GenerationRequest` contributes exactly three
     * things — the prompt, the lyrics, and whether it is instrumental — because
     * those are the three the domain actually means. Letting an operator's
     * request set `infer_step` or `guidance_scale` would be letting it set
     * what the engine costs to run.
     *
     * @return array<string, mixed>
     */
    private function body(GenerationRequest $request, string $outputPath): array
    {
        $defaults = is_array($this->settings['defaults'] ?? null) ? $this->settings['defaults'] : [];

        return [
            // Worker-chosen, always. See the class comment.
            'checkpoint_path' => (string) ($this->settings['checkpoint_path'] ?? ''),
            'device_id' => (int) ($this->settings['device_id'] ?? 0),
            'bf16' => (bool) ($this->settings['bf16'] ?? true),
            'torch_compile' => (bool) ($this->settings['torch_compile'] ?? false),
            'output_path' => $outputPath,

            // What the domain actually means.
            'prompt' => $this->style($request),
            // The contract's field is a plain string and has no null. An
            // instrumental sends an empty one, which is what "no words" is.
            'lyrics' => $request->instrumental ? '' : (string) ($request->lyrics ?? ''),

            // Rendering parameters, from configuration, with the reference
            // server's own documented defaults where an operator set none.
            'audio_duration' => (float) ($defaults['audio_duration'] ?? 60.0),
            'infer_step' => (int) ($defaults['infer_step'] ?? 60),
            'guidance_scale' => (float) ($defaults['guidance_scale'] ?? 15.0),
            'scheduler_type' => (string) ($defaults['scheduler_type'] ?? 'euler'),
            'cfg_type' => (string) ($defaults['cfg_type'] ?? 'apg'),
            'omega_scale' => (float) ($defaults['omega_scale'] ?? 10.0),
            // A list, per the contract. Empty means the engine chooses, which
            // is the honest default: a fixed seed here would make every
            // generation on an installation identical.
            'actual_seeds' => $this->seeds($defaults),
            'guidance_interval' => (float) ($defaults['guidance_interval'] ?? 0.5),
            'guidance_interval_decay' => (float) ($defaults['guidance_interval_decay'] ?? 0.0),
            'min_guidance_scale' => (float) ($defaults['min_guidance_scale'] ?? 3.0),
            'use_erg_tag' => (bool) ($defaults['use_erg_tag'] ?? true),
            'use_erg_lyric' => (bool) ($defaults['use_erg_lyric'] ?? true),
            'use_erg_diffusion' => (bool) ($defaults['use_erg_diffusion'] ?? true),
            'oss_steps' => [],
            'guidance_scale_text' => (float) ($defaults['guidance_scale_text'] ?? 0.0),
            'guidance_scale_lyric' => (float) ($defaults['guidance_scale_lyric'] ?? 0.0),
        ];
    }

    /**
     * ACE-Step's `prompt` is a style tag list rather than a description.
     *
     * SaniTube keeps the two apart — a prompt and a style prompt are different
     * fields on a generation — so both are sent, joined, rather than one being
     * silently dropped.
     */
    private function style(GenerationRequest $request): string
    {
        $parts = array_filter([
            trim($request->prompt),
            $request->stylePrompt === null ? '' : trim($request->stylePrompt),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return list<int>
     */
    private function seeds(array $defaults): array
    {
        $configured = $defaults['seeds'] ?? null;

        if (! is_array($configured)) {
            return [];
        }

        $seeds = [];

        foreach ($configured as $seed) {
            if (is_int($seed)) {
                $seeds[] = $seed;
            }
        }

        return $seeds;
    }

    private function endpoint(): string
    {
        // No default. An engine address is a deployment fact and inventing
        // `http://localhost:8000` would make a misconfigured worker look like
        // a working one until it silently talked to whatever was on that port.
        return rtrim((string) ($this->settings['endpoint'] ?? ''), '/');
    }

    private function timeout(): int
    {
        // Generous, because the reference server loads the model per request.
        return max(1, (int) ($this->settings['timeout_seconds'] ?? 900));
    }

    private function healthTimeout(): int
    {
        return max(1, (int) ($this->settings['health_timeout_seconds'] ?? 5));
    }
}
