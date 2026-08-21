<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use InvalidArgumentException;
use SaniTube\MusicGeneration\Enums\ExecutionMode;

/**
 * What happened when a supplier was asked.
 *
 * One of two things, and the type exists so that callers must decide which:
 * either the work is **finished** and the results are here, or it is **in
 * flight** and there is a reference to come back with. Before GEN-007 only the
 * second was expressible, so a synchronous supplier had no way to say "here it
 * is" except by pretending to have started something.
 *
 * Deliberately not a status. {@see GenerationStatus} describes where a provider
 * says a job stands; this describes the shape of a single answer, at the moment
 * it is given, and it is consumed immediately rather than stored.
 *
 * Constructed only through the two named factories. A public constructor would
 * let a caller build the state this type exists to exclude — finished *and*
 * carrying a reference, or neither — and the whole value of the union is that
 * such a thing cannot be made.
 */
final readonly class GenerationExecution
{
    /**
     * @param  list<ProviderResult>  $results
     */
    private function __construct(
        public ExecutionMode $mode,
        public bool $finished,
        public ?string $providerJobId,
        public array $results,
        public ?string $model,
    ) {}

    /**
     * The supplier answered with the audio.
     *
     * @param  list<ProviderResult>  $results  at least one; a completed generation
     *                                         with nothing in it is a provider fault
     *
     * @throws InvalidArgumentException
     */
    public static function completed(array $results, ?string $model = null): self
    {
        if ($results === []) {
            // Refused at construction rather than discovered three services
            // later. A "completed" execution with no audio would travel all the
            // way to a Completed generation carrying no takes, which is a row
            // an operator cannot act on and cannot explain.
            throw new InvalidArgumentException(
                'A completed execution must carry at least one result. A supplier that reported success '
                    .'and returned nothing has failed, and belongs in the failure path.',
            );
        }

        return new self(ExecutionMode::Synchronous, true, null, $results, $model);
    }

    /**
     * The supplier took the work and will have it later.
     *
     * @throws InvalidArgumentException
     */
    public static function inFlight(
        string $providerJobId,
        ExecutionMode $mode = ExecutionMode::AsynchronousPolling,
        ?string $model = null,
    ): self {
        if (trim($providerJobId) === '') {
            // The one thing an asynchronous answer must contain. Without it
            // nothing can ever be collected, and the generation would sit
            // PROCESSING for ever looking like work in progress.
            throw new InvalidArgumentException(
                'An in-flight execution must carry the provider\'s job identifier: without one, nothing '
                    .'can ever collect the result.',
            );
        }

        if ($mode === ExecutionMode::Synchronous) {
            throw new InvalidArgumentException(
                'A synchronous execution cannot be in flight. A provider that might not be finished when '
                    .'it answers is asynchronous, whatever its documentation calls it.',
            );
        }

        return new self($mode, false, trim($providerJobId), [], $model);
    }
}
