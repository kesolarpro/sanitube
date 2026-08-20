<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Exceptions;

use InvalidArgumentException;

final class UnknownTranscriptionProvider extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self(sprintf('[%s] is not a configured transcription provider.', $name));
    }

    public static function blank(): self
    {
        return new self('A transcription provider must be named. Empty configuration is normalised to "none" at the configuration boundary, so a blank name here is a caller bug.');
    }
}
