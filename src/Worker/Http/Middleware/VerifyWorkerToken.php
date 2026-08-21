<?php

declare(strict_types=1);

namespace SaniTube\Worker\Http\Middleware;

use SaniTube\Api\Http\Middleware\VerifiesSharedToken;

/**
 * Guards every worker route.
 *
 * Inherits the property that matters most: **no token configured means the
 * routes do not exist.** Almost every SaniTube installation is Core only —
 * cPanel, a small VPS — and none of them should advertise a worker, still less
 * expose one that fell back to something permissive.
 *
 * A token of its own, never the catalogue API's. They are different powers: one
 * reads a catalogue, this one **spends GPU time, runs binaries and writes into
 * shared storage.** An integration granted one has no business holding the
 * other, and a single token would make that impossible to arrange.
 *
 * Compared with `hash_equals` by the base class, and **never logged** — not the
 * value, not a prefix, not a fingerprint. A truncated token tells an attacker
 * whether their guess is close; the worker's configured *name* is what an audit
 * line carries instead.
 */
final class VerifyWorkerToken extends VerifiesSharedToken
{
    protected function tokenConfigKey(): string
    {
        return 'worker.token';
    }

    protected function headerConfigKey(): string
    {
        return 'worker.token_header';
    }

    protected function defaultHeader(): string
    {
        return 'X-SaniTube-Worker-Token';
    }

    protected function clientName(): string
    {
        return 'worker';
    }
}
