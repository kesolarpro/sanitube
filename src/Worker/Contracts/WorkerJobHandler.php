<?php

declare(strict_types=1);

namespace SaniTube\Worker\Contracts;

use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;

/**
 * Something a worker can actually do.
 *
 * One handler per capability, registered by the module that owns the work —
 * music generation registers its own, and a future media handler will register
 * itself the same way. The worker module knows nothing about what any of them
 * do, which is what stops it becoming the place every subsystem reaches into.
 *
 * `isRunnable()` is separate from being registered, and the distinction is the
 * useful one: a worker installation may carry the code for a capability whose
 * *engine* is absent — FFmpeg not installed, ACE-Step not configured — and
 * saying so is more useful than either hiding the capability or accepting a job
 * that must fail.
 */
interface WorkerJobHandler
{
    public function capability(): WorkerCapability;

    /**
     * Whether this worker can carry out the work right now.
     *
     * Asked during the capability handshake, so it must be **cheap**: a
     * configuration read, a file existence check, at most a cached version
     * probe. Never the work itself, and never a network call to a third party.
     */
    public function isRunnable(): bool;

    /**
     * Do the work.
     *
     * The payload has already been validated by the capability's own form
     * request. What comes back is JSON-encodable and is the worker's answer;
     * it must never contain a path on this host, a credential, or a supplier's
     * own error text.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws WorkerJobFailed
     */
    public function handle(array $payload): array;

    /**
     * The rules the payload for this capability must satisfy.
     *
     * Declared by the handler rather than by a controller, because the
     * controller is generic and must stay that way. **This list is a security
     * boundary**: a field absent from it is a field a caller cannot set, and
     * for the generation handler that is what keeps a request away from a
     * checkpoint path and a GPU.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
