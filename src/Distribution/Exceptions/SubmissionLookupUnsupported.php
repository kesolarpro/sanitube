<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Exceptions;

use RuntimeException;
use SaniTube\Distribution\Contracts\Distributor;

/**
 * This distributor cannot be asked whether it holds a submission.
 *
 * Thrown by {@see Distributor::findSubmission()}
 * when the provider offers no way to look a submission up by idempotency key.
 *
 * It is deliberately not `null`. `null` means "I looked and I have nothing",
 * which makes a retry safe; this means "I cannot look", which does not. A
 * contract that collapsed the two would turn every distributor without a
 * lookup endpoint into one that confidently reports an empty account.
 */
final class SubmissionLookupUnsupported extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self(sprintf(
            'The [%s] distributor cannot be asked whether it already holds a submission, so an '
                .'unconfirmed delivery has to be resolved by a person.',
            $provider,
        ));
    }
}
