<?php

declare(strict_types=1);

namespace SaniTube\Worker;

use Closure;
use SaniTube\Worker\Contracts\WorkerJobHandler;
use SaniTube\Worker\Enums\WorkerCapability;

/**
 * What this installation can be asked to do as a worker.
 *
 * Handlers register themselves from the module that owns the work, so the
 * worker module never learns what generation or media processing are. Adding a
 * capability is one `register()` call in one service provider, and neither this
 * class nor the protocol changes.
 *
 * **Handlers are registered lazily**, as a factory rather than an instance. A
 * registry that built every handler at boot would make booting cost whatever
 * the most expensive handler's dependencies cost, on every request, including
 * the overwhelming majority that are not worker requests at all — and it would
 * freeze those dependencies at the moment the container was still being
 * assembled. Found by a test: the generation handler pulled in the storage
 * manager during boot, which reads its configuration once, before the
 * configuration was finished.
 *
 * **Registered is not the same as runnable**, and both are reported. A worker
 * may carry the code for a capability whose engine is missing — no FFmpeg, no
 * ACE-Step endpoint — and the difference between "this worker does not do that"
 * and "this worker does that but cannot right now" is exactly what an operator
 * needs to see.
 */
final class WorkerRegistry
{
    /** @var array<string, Closure(): WorkerJobHandler> */
    private array $factories = [];

    /** @var array<string, WorkerJobHandler> */
    private array $resolved = [];

    /**
     * @param  Closure(): WorkerJobHandler  $factory
     */
    public function register(WorkerCapability $capability, Closure $factory): void
    {
        $this->factories[$capability->value] = $factory;
    }

    public function handlerFor(WorkerCapability $capability): ?WorkerJobHandler
    {
        if (! isset($this->factories[$capability->value])) {
            return null;
        }

        return $this->resolved[$capability->value] ??= ($this->factories[$capability->value])();
    }

    /**
     * Capabilities this worker both knows and can currently carry out.
     *
     * What Core is told, and what it selects on. Enum order rather than
     * registration order, so two workers can be compared and a screen does not
     * shuffle between requests.
     *
     * @return list<WorkerCapability>
     */
    public function runnable(): array
    {
        return array_values(array_filter(
            WorkerCapability::cases(),
            fn (WorkerCapability $capability): bool => $this->handlerFor($capability)?->isRunnable() ?? false,
        ));
    }

    /**
     * Everything registered, whether or not it can run.
     *
     * @return list<WorkerCapability>
     */
    public function registered(): array
    {
        return array_values(array_filter(
            WorkerCapability::cases(),
            fn (WorkerCapability $capability): bool => isset($this->factories[$capability->value]),
        ));
    }
}
