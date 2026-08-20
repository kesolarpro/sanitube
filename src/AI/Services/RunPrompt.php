<?php

declare(strict_types=1);

namespace SaniTube\AI\Services;

use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiManager;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\Models\AiInvocation;

/**
 * The one way the rest of SaniTube asks a model anything.
 *
 * Domain code never holds an `AiProvider`. It calls this with a purpose and a
 * prompt, and gets a completion that is safe to ignore. Three things follow
 * from routing everything through one place:
 *
 *   - **Every call is in the ledger**, including the ones that failed and the
 *     ones that never happened because no key is configured. A ledger that
 *     records only successes cannot answer "why did nothing get suggested
 *     last night".
 *   - **Nothing throws.** An unavailable provider and a vendor outage both
 *     produce a completion that reports itself unusable. AI in SaniTube
 *     assists a person who could do the job without it, so a feature that
 *     breaks the workflow when the vendor is down has inverted that.
 *   - **The purpose is SaniTube's word**, not the vendor's — "suggest_title",
 *     never "chat.completions". A ledger grouped by vendor endpoint answers no
 *     question anyone actually asks.
 */
final readonly class RunPrompt
{
    public function __construct(private AiManager $providers) {}

    public function handle(string $purpose, AiPrompt $prompt, ?string $providerName = null): AiCompletion
    {
        $provider = $providerName === null
            ? $this->providers->default()
            : $this->providers->provider($providerName);

        $startedAt = hrtime(true);
        $completion = $this->enforceShape($prompt, $provider->complete($prompt));
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->record($purpose, $prompt, $completion, $provider->name(), $durationMs);

        return $completion;
    }

    /**
     * An answer that was supposed to be machine-readable and is not.
     *
     * **Malformed structured output is a failure, never partially trusted
     * data.** The tempting alternative is to salvage it — pull the object out
     * of a fenced code block, take the first `{`, regex the fields that did
     * arrive — and every one of those turns a model that ignored the contract
     * into a suggestion somebody accepts without knowing it was reconstructed.
     * A schema that is enforced most of the time is not enforced.
     *
     * Converted here rather than in each adapter, so the rule holds for every
     * provider including ones written later, and so the ledger records the
     * call as failed — which is the only way "why did nothing get suggested
     * last night" has an answer.
     */
    private function enforceShape(AiPrompt $prompt, AiCompletion $completion): AiCompletion
    {
        if (! $prompt->requiresStructuredOutput() || ! $completion->isUsable()) {
            return $completion;
        }

        if ($completion->json() !== null) {
            return $completion;
        }

        // The text is not carried into the failure. It is the model's output
        // over this installation's catalogue data, and a failure message goes
        // to a screen and into a log.
        return AiCompletion::failed(
            $completion->provider,
            'The provider answered, and the answer was not in the shape that was required.',
        );
    }

    private function record(
        string $purpose,
        AiPrompt $prompt,
        AiCompletion $completion,
        string $providerName,
        int $durationMs,
    ): void {
        $storeText = (bool) config('ai.ledger.store_text', false);

        AiInvocation::query()->create([
            'provider' => $providerName,
            'model' => $completion->model,
            'purpose' => $purpose,
            'prompt_version' => $prompt->promptVersion,
            'succeeded' => $completion->isUsable(),
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'duration_ms' => $durationMs,
            // Off by default. These are the columns most likely to accumulate
            // copies of catalogue data, and turning them on is a debugging
            // decision with a retention consequence.
            'prompt_text' => $storeText ? trim($prompt->instruction."\n\n".$prompt->input) : null,
            'completion_text' => $storeText ? $completion->text : null,
            'failure_message' => $completion->failureMessage,
        ]);
    }
}
