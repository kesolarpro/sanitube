<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use SaniTube\MusicGeneration\Contracts\DeclaresCapabilities;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\GenerationExecution;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\ProviderResult;
use SaniTube\MusicGeneration\Worker\WorkerGenerationFailed;
use Throwable;

/**
 * Self-hosted ACE-Step, reached through a generation worker.
 *
 * **This provider does not talk to ACE-Step.** It talks to a SaniTube
 * generation worker, which is the installation running on the host that has the
 * GPU. The worker talks to ACE-Step over its published contract, opens the file
 * ACE-Step wrote, and stages the bytes into the storage both sides already
 * share. What comes back here is a **storage key**.
 *
 * That indirection is the whole design, and it exists because of one line in
 * ACE-Step's verified contract: `POST /generate` answers with `output_path`, a
 * path on the engine's own filesystem. Core running on cPanel cannot open a
 * path on a GPU box, and requiring it to — a shared mount between a shared
 * hosting account and a GPU server — is a deployment SaniTube must not demand.
 * So the path never leaves the worker, and Core is handed something it already
 * knows how to ingest from anywhere.
 *
 * Synchronous, because ACE-Step is: {@see SynchronousMusicGenerationProvider},
 * and no job identifier is invented for a job that has already finished. See
 * ADR-0019.
 *
 * **Optional.** An installation with no worker configured has no ACE-Step, and
 * `isAvailable()` says so without reaching the network. Core boots, the studio
 * renders, and everything that is not generation carries on.
 */
final readonly class SelfHostedAceStepProvider implements DeclaresCapabilities, SynchronousMusicGenerationProvider
{
    /**
     * @param  array<string, mixed>  $settings  the `generation.worker` block
     */
    public function __construct(
        private HttpFactory $http,
        private array $settings = [],
        private string $name = 'acestep',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Configured, not reachable.
     *
     * Asked on a page load, so it makes no request: UI-002 settled that no
     * screen probes a provider to render. A worker that is configured and down
     * fails at generation time with a reason, which is the honest place for it.
     */
    public function isAvailable(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    /**
     * What this engine does, read from its own repository.
     *
     * Text and lyrics to music, an instrumental when asked, and it runs on
     * hardware the operator owns. Notably **absent**: `CANCEL`, because a
     * synchronous call has nothing left running to stop; `WEBHOOK`, because it
     * has no callback; `STEM_SEPARATION`, `EXTEND` and `REMIX`, because the
     * verified `infer-api.py` contract exposes none of them. A capability
     * listed here that the contract does not document would be a guess, and
     * ADR-0018 exists to keep guesses out of adapters.
     *
     * @return list<GenerationCapability>
     */
    public function capabilities(): array
    {
        return [
            GenerationCapability::TextToMusic,
            GenerationCapability::LyricsToMusic,
            GenerationCapability::Instrumental,
            GenerationCapability::SelfHosted,
        ];
    }

    public function generate(GenerationRequest $request): GenerationExecution
    {
        // The worker names the file and the object; this is only an identity to
        // correlate them by, and the worker sanitises it regardless.
        $reference = bin2hex(random_bytes(16));

        try {
            $response = $this->http
                ->timeout($this->timeout())
                ->acceptJson()
                ->asJson()
                ->withHeaders([$this->tokenHeader() => $this->token()])
                ->post($this->url().'/api/generation/worker/render', [
                    'reference' => $reference,
                    'prompt' => $request->prompt,
                    'style_prompt' => $request->stylePrompt,
                    'lyrics' => $request->instrumental ? null : $request->lyrics,
                    'instrumental' => $request->instrumental,
                    'language' => $request->effectiveLanguage()->code,
                ]);
        } catch (Throwable) {
            // The message is not carried: a client exception quotes the
            // request, and this one carries the worker's address and its token
            // header. See ProviderFailure.
            throw WorkerGenerationFailed::because('WORKER_UNREACHABLE');
        }

        if ($response->status() === 422) {
            $code = $response->json('code');

            // The worker's own machine-readable reason, passed through
            // unchanged. It is a code from a closed set, never free text.
            throw WorkerGenerationFailed::because(is_string($code) ? $code : 'WORKER_REFUSED');
        }

        if (! $response->successful()) {
            throw WorkerGenerationFailed::because('WORKER_REFUSED');
        }

        $key = $response->json('storage_key');

        if (! is_string($key) || trim($key) === '') {
            throw WorkerGenerationFailed::because('WORKER_RETURNED_NO_OBJECT');
        }

        return GenerationExecution::completed(
            [new ProviderResult(
                providerResultId: $reference,
                // A storage reference, resolved by StorageGeneratedAudioReader.
                // Not a URL, not a path, and not something a browser is ever
                // shown -- the studio read models drop `source_reference`.
                sourceReference: StorageGeneratedAudioReader::SCHEME.trim($key),
                durationMs: null,
                raw: [
                    // Evidence, kept because provenance is worth having and
                    // because a mismatch later is worth being able to see.
                    'bytes' => $response->json('bytes'),
                    'checksum' => $response->json('checksum'),
                ],
            )],
            model: $this->model(),
        );
    }

    /**
     * What produced this audio, for provenance.
     *
     * From configuration, because the checkpoint an operator runs is theirs to
     * name. Never sent to the worker — the worker chooses its own checkpoint —
     * so this is a label, and it is recorded as one.
     */
    private function model(): ?string
    {
        $configured = $this->settings['model_label'] ?? null;

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    private function url(): string
    {
        $configured = $this->settings['url'] ?? null;

        return is_string($configured) ? rtrim(trim($configured), '/') : '';
    }

    private function token(): string
    {
        $configured = $this->settings['token'] ?? null;

        return is_string($configured) ? trim($configured) : '';
    }

    private function tokenHeader(): string
    {
        $configured = $this->settings['token_header'] ?? null;

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'X-SaniTube-Worker-Token';
    }

    private function timeout(): int
    {
        // Longer than the worker's own engine timeout would be pointless and
        // shorter would abandon work that is still running, so this is
        // configuration on both sides and the operator sets them together.
        return max(1, (int) ($this->settings['timeout_seconds'] ?? 960));
    }
}
