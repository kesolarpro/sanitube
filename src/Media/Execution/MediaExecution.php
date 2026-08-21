<?php

declare(strict_types=1);

namespace SaniTube\Media\Execution;

use SaniTube\Worker\Client\WorkerClient;

/**
 * Which machine a given kind of media work would run on.
 *
 * One question, asked the same way for every capability: *given what the
 * operator chose and what is actually true, where would this run?*
 *
 * **Availability is never inferred from configuration.** A worker URL and a
 * token prove somebody typed two settings; they prove nothing about whether a
 * worker answers or whether it does this particular work. So the remote path is
 * only offered when the handshake succeeds *and* advertises the capability, and
 * the local path only when the tool is actually there. That is the whole
 * difference between this class and a config read, and it is why a doctor
 * screen can be trusted.
 *
 * The handshake is cached by {@see WorkerClient}, so asking is cheap enough for
 * a page load. Nothing here runs the work to find out whether it could.
 *
 * This class deliberately does not know what a capability is. It knows only
 * that there are two roads, which one the operator asked for, and how to ask
 * each whether it is open.
 */
final readonly class MediaExecution
{
    public function __construct(private WorkerClient $worker) {}

    /**
     * Where this work would run.
     *
     * Both availabilities arrive as callables the caller supplies, and neither
     * is asked unless the strategy makes the answer matter. That is not merely
     * laziness: **whether the remote path can serve is the remote
     * implementation's own question**, and asking it here as well would put the
     * same knowledge in two places — where one of them eventually stops being
     * updated.
     *
     * @param  callable(): bool  $remotelyAvailable
     * @param  callable(): bool  $locallyAvailable
     */
    public function placeFor(callable $remotelyAvailable, callable $locallyAvailable): ExecutionPlace
    {
        $strategy = $this->strategy();

        if ($strategy->mayUseWorker() && $remotelyAvailable()) {
            return ExecutionPlace::RemoteWorker;
        }

        if (! $strategy->mayUseLocal()) {
            // A worker was chosen explicitly and cannot serve. It does not fall
            // back: an operator who selected a worker has usually done so for a
            // reason -- a shared host whose CPU limit ffprobe trips, a licence
            // that lives on one machine -- and a silent fallback would defeat
            // it without telling anybody.
            return ExecutionPlace::Nowhere;
        }

        return $locallyAvailable() ? ExecutionPlace::Local : ExecutionPlace::Nowhere;
    }

    public function strategy(): ExecutionStrategy
    {
        return ExecutionStrategy::parse(config('media.execution'));
    }

    /**
     * Whether a worker is configured at all — for a screen that has to
     * distinguish "no worker here" from "the worker will not do that".
     */
    public function workerConfigured(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * Whether the worker answered its handshake, whatever it can do.
     */
    public function workerReachable(): bool
    {
        return $this->worker->identity() !== null;
    }
}
