<?php

declare(strict_types=1);

namespace SaniTube\Worker;

/**
 * The version of the conversation between Core and a worker.
 *
 * Not the application version: Core and its worker do not have to be the
 * same build, and requiring that would turn every deploy into a lockstep
 * dance across machines. What they must agree on is the *wire* — the shape
 * of the handshake, the job envelope, the refusal codes — and this number
 * names that shape.
 *
 * The discipline: a change that an older counterpart would misread — a
 * renamed field, a payload whose meaning shifted, a new refusal semantics —
 * bumps this constant. A change an older counterpart safely ignores — a new
 * capability, an extra informational field — does not. Workers that predate
 * the constant announced nothing and spoke what is now called version 1,
 * which is why an absent announcement is read as 1 and not as unknown.
 *
 * A constant, deliberately not configuration: what protocol this build
 * speaks is a fact about the code, and an operator who could override it
 * would only be choosing which misunderstanding to have.
 */
final class WorkerProtocol
{
    public const VERSION = 1;
}
