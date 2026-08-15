<?php

declare(strict_types=1);

namespace SaniTube\Observability\Console;

use Illuminate\Console\Command;
use SaniTube\Observability\Health\OperationalHealthProbe;
use SaniTube\Observability\Health\OperationalHealthStore;

/**
 * Runs the real probes and stores the result.
 *
 * This is the only scheduled path that touches storage, providers and the
 * capability detectors. Screens read what it wrote; they never probe.
 *
 * It exits 0 even when providers are down. A provider outage is a fact to
 * record, not a failure of the recording — and a cron job that emails the
 * operator every five minutes because S3 is having a bad afternoon is a cron
 * job that gets deleted. The only failure it reports is being unable to store
 * what it found, because that leaves the previous snapshot looking current.
 */
final class RefreshOperationalHealthCommand extends Command
{
    protected $signature = 'sanitube:health:refresh';

    protected $description = 'Probe storage, providers and capabilities, and store the operational snapshot.';

    public function handle(OperationalHealthProbe $probe, OperationalHealthStore $store): int
    {
        $health = $probe->run();

        if (! $store->put($health)) {
            $this->components->error('The snapshot could not be stored. The previous one, if any, is now misleading.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Operational health recorded at %s.', $health->checkedAt->format(DATE_ATOM)));

        $storageHealthy = $health->storage['healthy'] ?? null;

        $this->components->twoColumnDetail(
            'Storage',
            is_bool($storageHealthy) ? ($storageHealthy ? 'healthy' : 'unhealthy') : 'unknown',
        );

        foreach ([['AI', $health->ai], ['Music generation', $health->generation]] as [$label, $provider]) {
            $available = $provider['available'] ?? null;
            $name = is_string($provider['name'] ?? null) ? $provider['name'] : 'unresolved';

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $label, $name),
                is_bool($available) ? ($available ? 'available' : 'unavailable') : 'unknown',
            );
        }

        foreach ($health->distributors as $distributor) {
            $available = $distributor['available'] ?? null;
            $name = is_string($distributor['name'] ?? null) ? $distributor['name'] : 'unresolved';

            $this->components->twoColumnDetail(
                sprintf('Distributor (%s)', $name),
                is_bool($available) ? ($available ? 'available' : 'unavailable') : 'unknown',
            );
        }

        return self::SUCCESS;
    }
}
