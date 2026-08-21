<?php

declare(strict_types=1);

namespace SaniTube\Media\Execution;

/**
 * Where heavy media work runs.
 *
 * SaniTube installs on a cPanel account with no FFmpeg and on a VPS with a full
 * toolchain, and both are supported deployments. Until WRK-002 the platform had
 * one answer — run it here — and a shared host simply went without.
 *
 * The choice is **configuration**, never a branch in domain logic. Nothing in
 * the analysis or fingerprint services knows which machine ran the binary, and
 * that is the property this enum exists to protect.
 */
enum ExecutionStrategy: string
{
    /**
     * Run it on this machine.
     *
     * What every installation did before WRK-002, and still the right answer
     * for a VPS with the tools installed: no network, no second host, no
     * object round trip.
     */
    case Local = 'local';

    /**
     * Send it to a worker.
     *
     * Chosen explicitly when an operator means it. If the worker cannot do the
     * work, the capability is **unavailable** — it does not quietly fall back,
     * because an operator who selected a worker has usually done so for a
     * reason a silent fallback would defeat.
     */
    case RemoteWorker = 'remote_worker';

    /**
     * Prefer a worker, fall back to this machine.
     *
     * The forgiving default for an installation that has both, or that has a
     * worker some of the time. Precedence is deliberate: a worker is preferred
     * *when it advertises the capability*, because it is usually the better
     * machine — and local is what keeps working when it is not there.
     */
    case Auto = 'auto';

    /**
     * Read a configured value, tolerating spelling and refusing invention.
     *
     * An unrecognised value is `auto` rather than a boot failure: a typo in a
     * `.env` on a shared host must not take an installation down, and `auto` is
     * the strategy that copes with the widest range of environments.
     */
    public static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return self::Auto;
        }

        return self::tryFrom(str_replace('-', '_', strtolower(trim($value)))) ?? self::Auto;
    }

    public function mayUseWorker(): bool
    {
        return $this !== self::Local;
    }

    public function mayUseLocal(): bool
    {
        return $this !== self::RemoteWorker;
    }
}
