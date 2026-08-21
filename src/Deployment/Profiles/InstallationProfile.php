<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Profiles;

/**
 * The shapes a SaniTube installation comes in.
 *
 * A profile is a *strategy*, never a set of infrastructure values: it decides
 * things like "services are systemd units" versus "everything hangs off cron",
 * and leaves every domain, path, credential and port to configuration. Two
 * installations with the same profile can share nothing at all.
 *
 * Three of the five are detectable; two are choices. A machine can look like
 * cPanel or look like a root VPS, but no inspection can tell whether *this*
 * VPS should also run the media worker — that is what the operator wants, not
 * what the machine is. The advisor therefore only ever suggests from the
 * detectable three and names the other two as options.
 */
enum InstallationProfile: string
{
    /**
     * A shared cPanel account. No root, no system packages, no persistent
     * processes: the scheduler is a cron line, the queue is worked in short
     * bursts from it, and the frontend arrives prebuilt because Node is not
     * assumed to exist.
     */
    case Cpanel = 'CPANEL';

    /**
     * A VPS running Core: root or sudo, systemd services for queue workers,
     * a real web server strategy, HTTPS automation where the environment
     * permits.
     */
    case VpsCore = 'VPS_CORE';

    /**
     * VpsCore plus the media/generation worker on the same machine. A choice,
     * not a detection — see the enum docblock.
     */
    case VpsCoreAndWorker = 'VPS_CORE_AND_WORKER';

    /**
     * Only the worker: no Core screens, no catalogue. The worker runtime, its
     * token, its capabilities and a service unit. Also a choice.
     */
    case WorkerOnly = 'WORKER_ONLY';

    /**
     * Any host that can run PHP and a database but fits none of the shapes
     * above: no root, no systemd, perhaps not Linux. The application installs
     * and runs; services are the operator's to arrange, and the installer says
     * exactly which ones.
     */
    case CoreOnlyGeneric = 'CORE_ONLY_GENERIC';

    public function label(): string
    {
        return match ($this) {
            self::Cpanel => 'cPanel / shared hosting',
            self::VpsCore => 'VPS — Core',
            self::VpsCoreAndWorker => 'VPS — Core and worker',
            self::WorkerOnly => 'Worker only',
            self::CoreOnlyGeneric => 'Core only, generic host',
        };
    }

    public function includesCore(): bool
    {
        return $this !== self::WorkerOnly;
    }

    public function includesWorker(): bool
    {
        return $this === self::VpsCoreAndWorker || $this === self::WorkerOnly;
    }

    /**
     * Whether this profile may install system packages at all. Only root VPS
     * shapes may; a cPanel account must never try, and the generic profile
     * does not know enough about its host to be trusted with it.
     */
    public function installsSystemPackages(): bool
    {
        return $this === self::VpsCore || $this === self::VpsCoreAndWorker || $this === self::WorkerOnly;
    }

    /**
     * How scheduled work runs under this profile. Every profile has an
     * answer, because a SaniTube with no scheduler silently stops retrying,
     * importing and pruning — the doctor treats that as a real failure.
     */
    public function schedulerStrategy(): string
    {
        return match ($this) {
            self::Cpanel, self::CoreOnlyGeneric => 'cron',
            self::VpsCore, self::VpsCoreAndWorker => 'cron-or-systemd-timer',
            self::WorkerOnly => 'none',
        };
    }

    /**
     * How queued work runs. cPanel cannot keep a process alive, so the queue
     * is worked in bounded bursts from the scheduler — slower, but honest
     * about what shared hosting permits.
     */
    public function queueStrategy(): string
    {
        return match ($this) {
            self::Cpanel, self::CoreOnlyGeneric => 'scheduled-bursts',
            self::VpsCore, self::VpsCoreAndWorker => 'persistent-worker',
            self::WorkerOnly => 'none',
        };
    }
}
