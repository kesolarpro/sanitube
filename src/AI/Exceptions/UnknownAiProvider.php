<?php

declare(strict_types=1);

namespace SaniTube\AI\Exceptions;

use RuntimeException;

/**
 * A provider name nothing in the configuration describes.
 *
 * A configuration mistake rather than a runtime condition: an absent *key* is
 * an ordinary state the platform is built to keep working through, but an
 * absent *provider* means someone named something that does not exist, and
 * silently falling back would hide it until an audit asked which model wrote a
 * description.
 */
final class UnknownAiProvider extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $name, array $known): self
    {
        return new self(sprintf(
            'No AI provider named [%s] is configured. Known providers: %s.',
            $name,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}
