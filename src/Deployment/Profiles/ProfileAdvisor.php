<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Profiles;

use SaniTube\Deployment\Host\HostFacts;

/**
 * Reads the facts and suggests — never decides — a profile.
 *
 * Only three profiles are ever suggested, because only three are detectable:
 * a machine can look like cPanel, look like a root VPS, or look like neither.
 * Whether a VPS should *also* carry the worker, or carry *only* the worker,
 * is what the operator wants from the machine, and no inspection answers
 * that. Those two are returned as alternatives, spelled out, every time.
 *
 * Precedence is deliberate: cPanel evidence wins over everything, because a
 * cPanel server frequently *has* systemd and a package manager — for cPanel's
 * own use. Suggesting VPS_CORE there would have the installer trying to write
 * system services on a machine whose owner is the hosting company.
 */
final readonly class ProfileAdvisor
{
    public function advise(HostFacts $facts): ProfileAdvice
    {
        // The GPU fact rides along on the worker alternatives, because it is
        // the fact that decides what a worker *here* could do: media tools
        // run on CPU anywhere, a music model does not. Stated, never assumed
        // — and never a blocker, because a CPU-only worker is a legitimate
        // machine for analysis and fingerprinting.
        $gpu = $facts->nvidiaDriver
            ? 'an NVIDIA driver is loaded on this machine'
            : 'no NVIDIA driver is loaded here — media work runs on CPU, and model-based generation would stay blocked until a GPU and driver exist';

        $alternatives = [
            sprintf(
                '%s — choose it to run media and generation work on this same machine (%s).',
                InstallationProfile::VpsCoreAndWorker->value,
                $gpu,
            ),
            sprintf(
                '%s — choose it on a machine that should only serve a Core installed elsewhere.',
                InstallationProfile::WorkerOnly->value,
            ),
        ];

        if ($facts->isCpanel()) {
            return new ProfileAdvice(
                suggestion: InstallationProfile::Cpanel,
                reasons: [sprintf('cPanel is installed on this server (%s).', implode(', ', $facts->cpanelEvidence))],
                cautions: array_merge(
                    [
                        sprintf(
                            'The PHP answering web requests can differ from this CLI (%s) on cPanel — '
                            .'check MultiPHP before trusting version-dependent behaviour.',
                            $facts->phpVersion,
                        ),
                    ],
                    $this->sharedCautions($facts),
                ),
                alternatives: $alternatives,
            );
        }

        if ($facts->osFamily !== 'Linux') {
            return new ProfileAdvice(
                suggestion: InstallationProfile::CoreOnlyGeneric,
                reasons: [sprintf(
                    'This is %s. Service automation here targets Linux; on other systems the application '
                    .'installs and runs, and services are yours to arrange.',
                    $facts->osFamily,
                )],
                cautions: $this->sharedCautions($facts),
                alternatives: $alternatives,
            );
        }

        $missing = $this->missingForVps($facts);

        if ($missing === []) {
            return new ProfileAdvice(
                suggestion: InstallationProfile::VpsCore,
                reasons: $this->vpsReasons($facts),
                cautions: $this->sharedCautions($facts),
                alternatives: $alternatives,
            );
        }

        return new ProfileAdvice(
            suggestion: InstallationProfile::CoreOnlyGeneric,
            reasons: [
                'A full VPS installation needs root, systemd and a package manager. Missing here: '
                    .implode(', ', $missing).'.',
            ],
            cautions: $this->sharedCautions($facts),
            alternatives: $alternatives,
        );
    }

    /**
     * @return list<string>
     */
    private function vpsReasons(HostFacts $facts): array
    {
        $reasons = [sprintf(
            '%s with root, systemd and %s.',
            $facts->distributionName ?? 'Linux',
            (string) $facts->packageManager,
        )];

        if ($facts->webServers !== []) {
            $reasons[] = sprintf('Web server already present: %s.', implode(', ', $facts->webServers));
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function missingForVps(HostFacts $facts): array
    {
        $missing = [];

        // Null means "could not ask" (no posix extension), and the honest
        // treatment is the cautious one: do not promise root-only automation
        // on the strength of an unanswered question.
        if ($facts->isRoot !== true) {
            $missing[] = $facts->isRoot === null ? 'root (undeterminable — posix is absent)' : 'root';
        }

        if (! $facts->hasSystemd) {
            $missing[] = 'systemd';
        }

        if ($facts->packageManager === null) {
            $missing[] = 'a package manager';
        }

        return $missing;
    }

    /**
     * True on any shape, and worth saying before a single stage runs.
     *
     * @return list<string>
     */
    private function sharedCautions(HostFacts $facts): array
    {
        $cautions = [];

        if ($facts->cron === null && $facts->hasSystemd === false) {
            $cautions[] = 'Neither crontab nor systemd was found: the scheduler has nowhere to run, '
                .'and without it imports, retries and pruning silently stop.';
        }

        if (! $facts->canRunProcesses) {
            $cautions[] = 'proc_open is unavailable, so this PHP cannot run Composer, FFmpeg or any '
                .'other tool itself — steps that need them must run from a shell or another PHP.';
        }

        if ($facts->openBasedir !== null) {
            $cautions[] = 'open_basedir is active; paths outside it are invisible to PHP, which can '
                .'hide binaries and directories that actually exist.';
        }

        if ($facts->composer === null) {
            $cautions[] = 'Composer was not found in PATH. Dependency installation needs it — or a '
                .'deployment artifact that already carries vendor/.';
        }

        return $cautions;
    }
}
