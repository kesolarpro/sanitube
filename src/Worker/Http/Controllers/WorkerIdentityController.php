<?php

declare(strict_types=1);

namespace SaniTube\Worker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use SaniTube\Worker\Support\ToolVersions;
use SaniTube\Worker\WorkerIdentity;
use SaniTube\Worker\WorkerRegistry;

/**
 * The capability handshake.
 *
 * What Core asks before it asks for anything else, and it must stay cheap
 * enough to poll: a configuration read, the registry's own state, and version
 * strings that are cached for an hour. **No work, and no third-party call.** A
 * handshake that generated a second of audio to prove it could would be a
 * handshake nobody dares run.
 *
 * It reports what this worker can do *now* and what it knows how to do *at
 * all*, because the difference between "does not do that" and "does that but
 * cannot right now" is the one an operator acts on.
 */
final class WorkerIdentityController
{
    public function __invoke(WorkerRegistry $registry, ToolVersions $tools): JsonResponse
    {
        return new JsonResponse((new WorkerIdentity(
            name: $this->name(),
            version: (string) config('app.version', 'dev'),
            capabilities: $registry->runnable(),
            registered: $registry->registered(),
            tools: $tools->all(),
        ))->toArray());
    }

    /**
     * Configured, never derived.
     *
     * A hostname changes when a machine is rebuilt. An audit line naming which
     * worker did something has to outlive that.
     */
    private function name(): string
    {
        $configured = config('worker.identity');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'unnamed-worker';
    }
}
