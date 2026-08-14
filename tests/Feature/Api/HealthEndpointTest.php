<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Observability\Capabilities\Detectors\PhpRuntimeDetector;
use SaniTube\Observability\Capabilities\Detectors\SchedulerDetector;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    #[Test]
    public function liveness_is_public_and_reveals_nothing_about_the_environment(): void
    {
        $response = $this->getJson('api/v1/health');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function the_environment_endpoints_do_not_exist_until_a_token_is_configured(): void
    {
        // A fresh install must not expose its PHP extensions, storage provider
        // and database engine to the internet.
        config(['sanitube.health.token' => null]);

        $this->getJson('api/v1/health/ready')->assertNotFound();
        $this->getJson('api/v1/system/capabilities')->assertNotFound();
    }

    #[Test]
    public function they_reject_a_missing_or_wrong_token(): void
    {
        config(['sanitube.health.token' => 'correct-token']);

        $this->getJson('api/v1/system/capabilities')->assertUnauthorized();

        $this->withHeader('X-SaniTube-Health-Token', 'wrong-token')
            ->getJson('api/v1/system/capabilities')
            ->assertUnauthorized();
    }

    #[Test]
    public function a_valid_token_returns_the_capability_report(): void
    {
        config(['sanitube.health.token' => 'correct-token']);

        $response = $this->withHeader('X-SaniTube-Health-Token', 'correct-token')
            ->getJson('api/v1/system/capabilities');

        $response->assertOk()
            ->assertJsonStructure([
                'healthy',
                'capabilities' => [['key', 'label', 'status', 'detail', 'remediation', 'required', 'blocking']],
            ]);

        $keys = array_column($response->json('capabilities'), 'key');

        $this->assertContains('php', $keys);
        $this->assertContains('object_storage', $keys);
        $this->assertContains('queue', $keys);
    }

    #[Test]
    public function readiness_answers_503_while_a_required_capability_is_missing(): void
    {
        config([
            'sanitube.health.token' => 'correct-token',
            // The scheduler has never run in a test process, which is exactly
            // the "cron was never installed" production failure.
            'capabilities.detectors' => [
                SchedulerDetector::class,
            ],
        ]);

        $this->withHeader('X-SaniTube-Health-Token', 'correct-token')
            ->getJson('api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('blocking.0.key', 'scheduler');
    }

    #[Test]
    public function readiness_answers_200_when_nothing_is_blocking(): void
    {
        config([
            'sanitube.health.token' => 'correct-token',
            'capabilities.detectors' => [
                PhpRuntimeDetector::class,
            ],
        ]);

        $this->withHeader('X-SaniTube-Health-Token', 'correct-token')
            ->getJson('api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready');
    }

    #[Test]
    public function the_framework_liveness_probe_is_still_available(): void
    {
        $this->get('up')->assertOk();
    }
}
