<?php

declare(strict_types=1);

namespace SaniTube\Observability\Console;

use Illuminate\Console\Command;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Observability\Capabilities\CapabilityStatus;

final class HealthCommand extends Command
{
    protected $signature = 'sanitube:health
                            {--json : Output the report as JSON}
                            {--strict : Exit non-zero when any capability is missing, including optional ones}';

    protected $description = 'Report what this server can and cannot do';

    public function handle(CapabilityRegistry $registry): int
    {
        $report = $registry->report();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'healthy' => $report->isHealthy(),
                'capabilities' => $report->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report->all());
        }

        if ($this->option('strict')) {
            return $this->hasAnyGap($report->all()) ? self::FAILURE : self::SUCCESS;
        }

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private function render(array $capabilities): void
    {
        $rows = [];

        foreach ($capabilities as $capability) {
            $rows[] = [
                $capability->label,
                $this->decorate($capability->status),
                $capability->detail ?? '',
            ];
        }

        $this->newLine();
        $this->table(['Capability', 'Status', 'Detail'], $rows);

        foreach ($capabilities as $capability) {
            if ($capability->status === CapabilityStatus::Available || $capability->remediation === null) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('  <options=bold>%s</>', $capability->label));
            $this->line(sprintf('  %s', $capability->remediation));
        }

        $this->newLine();
    }

    private function decorate(CapabilityStatus $status): string
    {
        return match ($status) {
            CapabilityStatus::Available => '<fg=green>available</>',
            CapabilityStatus::Unavailable => '<fg=red>unavailable</>',
            CapabilityStatus::Degraded => '<fg=yellow>degraded</>',
            CapabilityStatus::Optional => '<fg=gray>optional</>',
        };
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private function hasAnyGap(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($capability->status !== CapabilityStatus::Available) {
                return true;
            }
        }

        return false;
    }
}
