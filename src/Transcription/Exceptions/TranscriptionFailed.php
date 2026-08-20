<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Exceptions;

use RuntimeException;

/**
 * A transcription that did not happen.
 *
 * Every one carries a machine-readable reason. The English is for a developer
 * reading a log — it is never rendered, and it never quotes a vendor's response
 * body, because those routinely echo back a request that contained a key.
 */
final class TranscriptionFailed extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function providerUnavailable(string $provider): self
    {
        return new self(sprintf('The [%s] transcription provider has no usable configuration.', $provider), 'TRANSCRIPTION_UNAVAILABLE');
    }

    /**
     * The supplier answered, and the answer was not usable.
     *
     * Deliberately not carrying the response body. A failing endpoint tends to
     * quote the request back, and the request carried a credential.
     */
    public static function unreadableResponse(string $provider): self
    {
        return new self(sprintf('The [%s] transcription provider returned something unreadable.', $provider), 'TRANSCRIPTION_UNREADABLE');
    }

    public static function callFailed(string $provider): self
    {
        return new self(sprintf('The call to [%s] did not complete.', $provider), 'TRANSCRIPTION_CALL_FAILED');
    }

    /**
     * The file is not something to send.
     *
     * Checked before the call rather than after, because the cost of finding
     * out from the supplier is a paid request and a wait.
     */
    public static function unreadableSource(): self
    {
        return new self('The audio could not be read from storage.', 'TRANSCRIPTION_SOURCE_UNREADABLE');
    }
}
