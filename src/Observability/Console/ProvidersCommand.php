<?php

declare(strict_types=1);

namespace SaniTube\Observability\Console;

use Illuminate\Console\Command;
use SaniTube\Observability\Certification\CertificationStatus;
use SaniTube\Observability\Certification\ProviderStanding;
use SaniTube\Observability\Certification\ProviderStandings;

/**
 * Where every external provider stands on this installation.
 *
 * Informational and read-only: it derives, it never probes. The commands
 * that actually talk to services (`sanitube:storage:check --certify`,
 * `sanitube:worker:check`) are what move a row to CERTIFIED — this screen
 * is where the operator sees that nothing else does.
 */
final class ProvidersCommand extends Command
{
    protected $signature = 'sanitube:providers {--json : Output the standings as JSON}';

    protected $description = 'Report every provider integration and its certification status';

    public function handle(ProviderStandings $standings): int
    {
        $rows = $standings->assess();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (ProviderStanding $standing): array => $standing->toArray(), $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->line('Provider standings');
        $this->newLine();

        foreach ($rows as $standing) {
            $this->line(sprintf(
                '  %s %-24s %s',
                $this->badge($standing->status),
                $standing->key,
                $standing->detail.($standing->certifiedAt !== null ? sprintf(' (certified %s)', $standing->certifiedAt) : ''),
            ));
        }

        return self::SUCCESS;
    }

    private function badge(CertificationStatus $status): string
    {
        return match ($status) {
            CertificationStatus::Certified => '<fg=green>'.str_pad($status->value, 23).'</>',
            CertificationStatus::ConfiguredUncertified => '<fg=yellow>'.str_pad($status->value, 23).'</>',
            CertificationStatus::BlockedExternal, CertificationStatus::Unavailable => '<fg=red>'.str_pad($status->value, 23).'</>',
            default => str_pad($status->value, 23),
        };
    }
}
