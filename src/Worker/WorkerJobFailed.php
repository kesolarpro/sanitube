<?php

declare(strict_types=1);

namespace SaniTube\Worker;

use RuntimeException;
use SaniTube\Foundation\Contracts\CarriesRefusalCode;

/**
 * A worker job that will not produce a result.
 *
 * Carries a machine-readable code and nothing else. Every one of these crosses
 * an authenticated boundary and is written into a Core log, so the rule holds
 * with more force here than anywhere: an engine's own message quotes a
 * traceback, and a client exception quotes the request that produced it —
 * including, on a configured deployment, an address and a token header.
 */
final class WorkerJobFailed extends RuntimeException implements CarriesRefusalCode
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('The worker refused: %s.', $reason));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function refusalCode(): string
    {
        return $this->reason;
    }
}
