<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

/**
 * Where a provider integration actually stands — a closed vocabulary,
 * because "is R2 working?" answered with a boolean is how a platform ships
 * claiming things nobody ever ran.
 *
 * The six states answer two different questions and it matters which:
 * the first four are about *this installation* (what has been configured
 * and proven here); the last two are about *the world* (what can be had at
 * all). A status must never drift between the columns — an unreachable
 * documentation site is BLOCKED_EXTERNAL however finished our side is, and
 * no amount of local configuration turns UNAVAILABLE into anything else.
 */
enum CertificationStatus: string
{
    /**
     * The integration exists; this installation has not configured it.
     * The ordinary state of every optional provider on a fresh install.
     */
    case NotConfigured = 'NOT_CONFIGURED';

    /**
     * Our side is built and held by tests, and there is nothing for the
     * operator to configure yet — or the built-in path (a fake, a manual
     * export) is the complete offering until a real counterparty exists.
     */
    case CodeReady = 'CODE_READY';

    /**
     * Credentials are in place and no real certification run has passed
     * against them — or one passed against a *different* configuration,
     * which is the same thing wearing a reassuring timestamp.
     */
    case ConfiguredUncertified = 'CONFIGURED_UNCERTIFIED';

    /**
     * A real certification run passed against the currently configured
     * service, and the record of it names when. The only state in this
     * enum that a test environment can never produce.
     */
    case Certified = 'CERTIFIED';

    /**
     * Complete on our side; progress needs something outside this
     * installation — a credential nobody has yet, an account under review,
     * documentation this environment cannot reach.
     */
    case BlockedExternal = 'BLOCKED_EXTERNAL';

    /**
     * The counterparty does not exist to be had: no published contract, no
     * API, nothing an adapter could honestly target. Configuration cannot
     * change this state; only the world can.
     */
    case Unavailable = 'UNAVAILABLE';
}
