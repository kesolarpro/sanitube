<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Exceptions;

use RuntimeException;

/**
 * A provider name nothing in the configuration describes.
 *
 * A configuration mistake rather than a runtime condition, and not silently
 * disabled: somebody who typed `sunoo` intended a provider, and quietly
 * turning generation off would hide it until a campaign produced nothing.
 */
final class UnknownGenerationProvider extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $name, array $known): self
    {
        return new self(sprintf(
            'No music generation provider named [%s] is configured. Known providers: %s.',
            $name,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}
