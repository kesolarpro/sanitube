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
 *   - **Every call passes the same two gates**, so a spend ceiling and a
 *     circuit breaker cannot be bypassed by a caller that resolved a provider
 *     for itself. One place to ask means one place to change.
 */
final readonly class RunPrompt
{
    public function __construct(
        private AiManager $providers,
        private AiSpendGuard $spend,
        private AiCircuitBreaker $circuit,
    ) {}

    public function handle(string $purpose, AiPrompt $prompt, ?string $providerName = null): AiCompletion
    {
        $provider = $providerName === null
            ? $this->providers->default()
            : $this->providers->provider($providerName);

        // Both gates ask before the call rather than after, and both produce a
        // completion rather than an exception -- an AI feature that threw when
        // a ceiling was reached would break the workflow it exists to assist.
        //
        // Recorded in the ledger like everything else, and marked `attempted:
        // false` so that a refusal cannot count towards the ceiling that
        // produced it. A quota that counted its own refusals would latch shut
        // and never reopen.
        $refusal = $this->refuseBefore($provider->name());

        if ($refusal instanceof AiCompletion) {
            $this->record($purpose, $prompt, $refusal, $provider->name(), 0);

            return $refusal;
        }

        $startedAt = hrtime(true);
        $completion = $this->enforceShape($prompt, $provider->complete($prompt));
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->record($purpose, $prompt, $completion, $provider->name(), $durationMs);

        return $completion;
    }

    /**
     * The two reasons this installation declines to make a call it could make.
     *
     * The spend ceiling is asked first, because it is the operator's own
     * decision and outranks a measurement. The breaker is asked second,
     * because "the vendor is down" is only worth saying to somebody who was
     * otherwise allowed to call.
     */
    private function refuseBefore(string $provider): ?AiCompletion
    {
        $exhausted = $this->spend->exhaustedWindow();

        if ($exhausted !== null) {
            return AiCompletion::quotaExhausted($provider, $exhausted);
        }

        return $this->circuit->isOpenFor($provider)
            ? AiCompletion::circuitOpen($provider)
            : null;
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

            // Whether a request actually left this server. The column a spend
            // ceiling counts, and never `succeeded`: a vendor answering 500
            // cost a request, and a missing key cost nothing.
            'attempted' => $completion->attempted,
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
