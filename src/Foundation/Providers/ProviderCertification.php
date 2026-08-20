<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Providers;

/**
 * How far an external provider has actually been proven on this installation.
 *
 * The vocabulary `docs/production-readiness.md` has used in prose since the
 * beginning, made checkable. The distinction that matters most is the one that
 * document opens with: **`BlockedExternal` is not "not built".** A provider can
 * be complete on this side and still be uncertifiable here, and conflating the
 * two turns "we cannot test this without an account" into "this does not work".
 *
 * These are about *the provider*, never about a request. A call that failed is
 * a failed call; it does not demote the provider, and a provider that has never
 * been called is not thereby broken.
 */
enum ProviderCertification: string
{
    /**
     * Nothing has been configured. The ordinary state of an optional provider,
     * and never an error.
     */
    case NotConfigured = 'NOT_CONFIGURED';

    /**
     * Configured, and complete on this side, but nobody has proved it against
     * the real service from this installation. Everything an adapter can be
     * without credentials stops here.
     */
    case ConfiguredUncertified = 'CONFIGURED_UNCERTIFIED';

    /**
     * A real call has succeeded against the real service. Only ever set by
     * something that actually made one — never by configuration being present,
     * which is the mistake this enum exists to prevent.
     */
    case Certified = 'CERTIFIED';

    /**
     * Complete here and blocked there: no account, no credentials, an approval
     * queue, or a contract nobody has signed. **Not a defect in this codebase**,
     * and reported differently so that it cannot be mistaken for one.
     */
    case BlockedExternal = 'BLOCKED_EXTERNAL';

    /**
     * Configured and currently not answering. A transient operational state,
     * distinct from never having been configured and from never having been
     * proven.
     */
    case Unavailable = 'UNAVAILABLE';

    /**
     * Whether this installation may attempt a call at all.
     *
     * Deliberately true for `ConfiguredUncertified`: an adapter nobody has
     * certified is exactly the one somebody has to call in order to certify it.
     */
    public function mayAttempt(): bool
    {
        return $this === self::ConfiguredUncertified || $this === self::Certified;
    }
}
