<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Host;

/**
 * What was observed about this machine — observations, never conclusions.
 *
 * "Which package manager exists" is a fact. "Therefore install packages" is a
 * decision, and it belongs to whoever consumes this: the profile advisor, an
 * installer stage, an operator reading `sanitube:host`. Keeping the two apart
 * is what lets three very different deployments read the same report.
 *
 * Every field is either a primitive or a list of names; nothing here is a
 * credential, so the whole object may be printed.
 */
final readonly class HostFacts
{
    /**
     * @param  list<string>  $cpanelEvidence
     * @param  list<string>  $webServers
     * @param  list<string>  $databaseClients
     */
    public function __construct(
        public string $osFamily,
        public ?string $distributionId,
        public ?string $distributionVersion,
        public ?string $distributionName,
        public ?bool $isRoot,
        public array $cpanelEvidence,
        public bool $hasSystemd,
        public ?string $cron,
        public ?string $packageManager,
        public array $webServers,
        public string $phpVersion,
        public ?string $phpBinary,
        public ?string $composer,
        public ?string $node,
        public ?string $git,
        public array $databaseClients,
        public bool $canRunProcesses,
        public ?string $openBasedir,
        public bool $nvidiaDriver = false,
        public ?string $nvidiaSmi = null,
    ) {}

    public function isCpanel(): bool
    {
        return $this->cpanelEvidence !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'os_family' => $this->osFamily,
            'distribution_id' => $this->distributionId,
            'distribution_version' => $this->distributionVersion,
            'distribution_name' => $this->distributionName,
            'is_root' => $this->isRoot,
            'is_cpanel' => $this->isCpanel(),
            'cpanel_evidence' => $this->cpanelEvidence,
            'has_systemd' => $this->hasSystemd,
            'cron' => $this->cron,
            'package_manager' => $this->packageManager,
            'web_servers' => $this->webServers,
            'php_version' => $this->phpVersion,
            'php_binary' => $this->phpBinary,
            'composer' => $this->composer,
            'node' => $this->node,
            'git' => $this->git,
            'database_clients' => $this->databaseClients,
            'can_run_processes' => $this->canRunProcesses,
            'open_basedir' => $this->openBasedir,
            'nvidia_driver' => $this->nvidiaDriver,
            'nvidia_smi' => $this->nvidiaSmi,
        ];
    }
}
