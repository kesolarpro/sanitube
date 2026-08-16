<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The submission never left this machine.
 *
 * An adapter throws this when it *knows* the distributor was not reached at
 * all — DNS failure, connection refused, TLS handshake rejected, a 401 on the
 * authentication round trip that precedes the body. In every one of those
 * cases the package demonstrably did not arrive, so the delivery is FAILED and
 * retrying is safe.
 *
 * It exists because the *default* has to be the opposite. Any other failure
 * during submission — a read timeout, a connection reset while waiting for the
 * response, a 502 from a gateway that had already forwarded the request —
 * leaves SaniTube genuinely unable to say whether the distributor has the
 * package, and that is not a state a retry may be offered for.
 *
 * Adapters are never obliged to throw this. Not throwing it means "I do not
 * know", which is the safe answer.
 */
final class SubmissionNotSent extends RuntimeException
{
    public static function because(string $why, ?Throwable $previous = null): self
    {
        return new self($why, 0, $previous);
    }
}
