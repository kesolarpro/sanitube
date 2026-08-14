<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use SaniTube\Observability\Capabilities\CapabilityRegistry;

final readonly class HealthController
{
    /**
     * Liveness: "this process is running and can answer HTTP".
     *
     * Nothing here may reach the database, the cache, object storage or a
     * capability detector. A probe that depends on those reports the process
     * as dead whenever a dependency is down, which is the opposite of what an
     * orchestrator needs in order to distinguish "restart this container" from
     * "the database is down".
     */
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Readiness: 503 while anything required is missing, so a load balancer or
     * a deployment script can hold traffic back instead of serving errors.
     */
    public function ready(CapabilityRegistry $registry): JsonResponse
    {
        $report = $registry->report();

        return new JsonResponse(
            [
                'status' => $report->isHealthy() ? 'ready' : 'not_ready',
                'blocking' => array_map(
                    static fn ($capability): array => $capability->toArray(),
                    $report->blocking(),
                ),
            ],
            $report->isHealthy() ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * The full capability report that backs the System screen.
     */
    public function capabilities(CapabilityRegistry $registry): JsonResponse
    {
        $report = $registry->report();

        return new JsonResponse([
            'healthy' => $report->isHealthy(),
            'capabilities' => $report->toArray(),
        ]);
    }
}
