<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker;

use RuntimeException;
use SaniTube\MusicGeneration\Contracts\CarriesRefusalCode;

/**
 * The worker will not hand this generation back.
 *
 * Carries a machine-readable reason and no supplier text. Every one of these
 * crosses an authenticated boundary and ends up in a Core log, so the rule the
 * rest of the module follows applies with more force here: an engine's own
 * message quotes a traceback, and a client exception quotes the request.
 */
final class WorkerGenerationFailed extends RuntimeException implements CarriesRefusalCode
{
    public function refusalCode(): string
    {
        return $this->reason;
    }

    private function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('The generation worker refused: %s.', $reason));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
