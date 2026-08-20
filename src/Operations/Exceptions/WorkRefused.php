<?php

declare(strict_types=1);

namespace SaniTube\Operations\Exceptions;

use RuntimeException;

/**
 * The platform declined to take on more background work.
 *
 * Not an error in the work being asked for. Both refusals here are about the
 * installation's current state, and both are recoverable by waiting or by an
 * operator lifting a switch — which is why they carry codes an interface can
 * translate into an instruction rather than an apology.
 */
final class WorkRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function paused(): self
    {
        return new self('Background work is paused on this installation.', 'BACKGROUND_WORK_PAUSED');
    }

    /**
     * More work than the queue has room for.
     *
     * The number is in the message for a log and in {@see $reason} for the
     * interface. An operator seeing this does not need a different platform;
     * they need to wait, or to raise a ceiling that was set for a smaller
     * machine.
     */
    public static function backlogSaturated(int $depth, int $ceiling): self
    {
        return new self(
            sprintf('The queue holds %d jobs and the ceiling is %d.', $depth, $ceiling),
            'BACKLOG_SATURATED',
        );
    }
}
