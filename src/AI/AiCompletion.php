<?php

declare(strict_types=1);

namespace SaniTube\AI;

/**
 * What a language model returned, plus everything needed to audit it.
 */
final readonly class AiCompletion
{
    public function __construct(
        public string $provider,
        public string $text,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public bool $refused = false,
        public ?string $failureMessage = null,

        /**
         * Whether a request actually left this server.
         *
         * Distinct from `refused`, and the distinction is what makes a spend
         * ceiling possible. A missing key, an exhausted quota and an open
         * circuit are all refusals that cost nothing; a vendor answering 500
         * is not a refusal and costs a request. Counting either by the other
         * gets the number wrong in a direction somebody pays for.
         */
        public bool $attempted = true,
    ) {}

    /**
     * No usable credentials on this installation. Not a failure of the
     * request, and deliberately distinct from one: a missing key is the state
     * of every fresh install and is fixed in the environment, not the code.
     */
    public static function unavailable(string $provider): self
    {
        return new self(
            provider: $provider,
            text: '',
            refused: true,
            failureMessage: 'No AI provider is configured on this installation.',
            attempted: false,
        );
    }

    /**
     * This installation has made as many calls as it allows itself.
     *
     * **Operational safety, not accounting.** It counts calls, because calls
     * are what this platform can actually observe; it holds no prices, no
     * currency and no balance, and it is not a step towards any of those.
     *
     * Nothing was sent, so nothing was spent — which is why this does not
     * count against the ceiling that produced it.
     */
    public static function quotaExhausted(string $provider, string $window): self
    {
        return new self(
            provider: $provider,
            text: '',
            refused: true,
            failureMessage: sprintf('The %s call ceiling for this installation has been reached.', $window),
            attempted: false,
        );
    }

    /**
     * The provider has failed enough times in a row to stop asking for now.
     *
     * A vendor having an outage answers every request the same way, and a
     * queue that keeps asking turns one outage into a few thousand billed
     * failures. The circuit closes itself when the cooldown passes.
     */
    public static function circuitOpen(string $provider): self
    {
        return new self(
            provider: $provider,
            text: '',
            refused: true,
            failureMessage: sprintf('The [%s] provider failed repeatedly and is not being called at the moment.', $provider),
            attempted: false,
        );
    }

    /**
     * The provider was called and did not produce usable text — an outage, a
     * rate limit, a rejected request, a response shape the adapter could not
     * read. Distinct from unavailable, because this one is worth retrying.
     */
    public static function failed(string $provider, string $message): self
    {
        return new self(provider: $provider, text: '', refused: true, failureMessage: $message);
    }

    /**
     * Decode a JSON completion, returning null rather than throwing: a model
     * that ignored the requested format is an expected outcome, not an error.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->text, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isUsable(): bool
    {
        return ! $this->refused && trim($this->text) !== '';
    }
}
