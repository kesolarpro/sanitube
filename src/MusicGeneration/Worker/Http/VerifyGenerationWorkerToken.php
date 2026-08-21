<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker\Http;

use SaniTube\Api\Http\Middleware\VerifiesSharedToken;

/**
 * Guards the generation worker endpoint.
 *
 * Extends the shared-token base rather than repeating it, and inherits the
 * property that matters most here: **no token configured means the route does
 * not exist.** Almost every SaniTube installation is Core only — cPanel, a
 * small VPS — and none of them should advertise a generation worker, still
 * less expose one that fell back to something permissive.
 *
 * A token of its own, not the internal API's. They are different powers: the
 * internal API reads a catalogue, and this one **spends GPU time and writes
 * into shared storage**. An integration granted one has no business holding
 * the other.
 */
final class VerifyGenerationWorkerToken extends VerifiesSharedToken
{
    protected function tokenConfigKey(): string
    {
        return 'generation.worker.token';
    }

    protected function headerConfigKey(): string
    {
        return 'generation.worker.token_header';
    }

    protected function defaultHeader(): string
    {
        return 'X-SaniTube-Worker-Token';
    }

    protected function clientName(): string
    {
        return 'generation-worker';
    }
}
