<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Exceptions;

use RuntimeException;
use SaniTube\MusicGeneration\Enums\GenerationCapability;

/**
 * A generation request the platform will not carry out.
 *
 * Every case carries a machine-readable `reason` the API surfaces, for the
 * same argument CAT-001 makes: a 422 with no reason is what makes a caller
 * guess, and a caller who guesses works around the check rather than fixing it.
 */
final class GenerationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function providerUnavailable(string $provider): self
    {
        return new self(
            sprintf(
                'The [%s] generation provider is not configured on this installation. Generation is '
                    .'optional; everything else continues to work.',
                $provider,
            ),
            'PROVIDER_UNAVAILABLE',
        );
    }

    public static function projectClosed(string $uuid): self
    {
        return new self(
            sprintf(
                'Project [%s] is finished and no longer accepts generations. Adding to it quietly '
                    .'would make "how many did that campaign produce" unanswerable.',
                $uuid,
            ),
            'PROJECT_CLOSED',
        );
    }

    /**
     * The installation has asked for as many generations as it allows itself.
     *
     * **Operational safety, not accounting.** It counts requests, holds no
     * price and no currency, and refusing here costs nothing — which is why a
     * refusal does not count against the ceiling that produced it.
     */
    public static function ceilingReached(string $window): self
    {
        return new self(
            sprintf(
                'This installation has reached its %s generation request ceiling. Nothing was sent, so '
                    .'nothing was spent; the window is rolling and will make room on its own.',
                $window,
            ),
            'CEILING_REACHED',
        );
    }

    /**
     * The provider has failed enough times in a row to stop asking for now.
     */
    public static function providerCircuitOpen(string $provider): self
    {
        return new self(
            sprintf(
                'The [%s] generation provider failed repeatedly and is not being called at the moment. '
                    .'The circuit closes itself once the cooldown passes.',
                $provider,
            ),
            'PROVIDER_CIRCUIT_OPEN',
        );
    }

    /**
     * The provider was asked for something it does not do.
     *
     * Named capabilities, not a bare refusal. "This provider cannot sing
     * supplied lyrics" is a sentence an operator can act on -- switch supplier,
     * or stop supplying lyrics -- and "the request was refused" is not.
     *
     * @param  list<GenerationCapability>  $missing
     */
    public static function providerIncapable(string $provider, array $missing): self
    {
        return new self(
            sprintf(
                'The [%s] generation provider does not support: %s. Nothing was sent.',
                $provider,
                implode(', ', array_map(
                    static fn (GenerationCapability $capability): string => $capability->value,
                    $missing,
                )),
            ),
            'PROVIDER_INCAPABLE',
        );
    }

    /**
     * Nothing configured on this installation can serve the request.
     *
     * Distinct from {@see providerIncapable()} on purpose: that one names a
     * supplier that fell short, this one says the search itself came back
     * empty. They lead to different actions -- change provider, versus
     * configure one -- so they are different codes.
     *
     * @param  list<GenerationCapability>  $required
     */
    public static function noCapableProvider(array $required): self
    {
        return new self(
            $required === []
                ? 'No generation provider is configured on this installation.'
                : sprintf(
                    'No configured generation provider supports: %s.',
                    implode(', ', array_map(
                        static fn (GenerationCapability $capability): string => $capability->value,
                        $required,
                    )),
                ),
            'NO_CAPABLE_PROVIDER',
        );
    }

    public static function notCancellable(string $status): self
    {
        return new self(
            sprintf('A generation in [%s] has already finished and cannot be cancelled.', $status),
            'NOT_CANCELLABLE',
        );
    }

    public static function resultHasNoAudio(string $uuid): self
    {
        return new self(
            sprintf(
                'Result [%s] carries no audio to import. A completed generation with nothing to '
                    .'download is a provider fault, not an empty track.',
                $uuid,
            ),
            'RESULT_HAS_NO_AUDIO',
        );
    }

    public static function generationNotComplete(string $status): self
    {
        return new self(
            sprintf('Results cannot be selected while the generation is [%s].', $status),
            'GENERATION_NOT_COMPLETE',
        );
    }
}
