<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use SaniTube\Observability\Capabilities\Detectors\PhpRuntimeDetector;
use SaniTube\Observability\Capabilities\Detectors\SchedulerDetector;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The throttle counters are per-IP and persist across requests by
        // design, so each test starts from a clean store.
        $this->app['cache']->store(config('sanitube.health.throttle.store'))->clear();
    }

    #[Test]
    public function liveness_is_public_and_reveals_nothing_about_the_environment(): void
    {
        $this->getJson('api/v1/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function liveness_touches_no_database(): void
    {
        // A liveness probe that queries the database stops answering exactly
        // when the database fails — and then reports the process as dead
        // instead of reporting the database as down.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('api/v1/health/live')->assertOk();

        $this->assertSame([], $queries, 'Liveness issued database queries: '.implode(' | ', $queries));
    }

    #[Test]
    public function liveness_runs_no_capability_detector(): void
    {
        config(['capabilities.detectors' => [ExplodingDetector::class]]);

        // If liveness ran detectors at all, this one would make it fail.
        $this->getJson('api/v1/health/live')->assertOk();

        $this->assertFalse(ExplodingDetector::$wasRun);
    }

    #[Test]
    public function liveness_is_rate_limited(): void
    {
        config(['sanitube.health.throttle.live.max_attempts' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('api/v1/health/live')->assertOk();
        }

        $this->getJson('api/v1/health/live')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function liveness_reports_the_remaining_allowance(): void
    {
        config(['sanitube.health.throttle.live.max_attempts' => 10]);

        $this->getJson('api/v1/health/live')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '9');
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
    public function the_privileged_endpoints_are_throttled_before_the_token_is_even_checked(): void
    {
        // Throttling first means an attacker cannot use the token check itself
        // as an oracle to guess at, one request at a time.
        config([
            'sanitube.health.token' => 'correct-token',
            'sanitube.health.throttle.privileged.max_attempts' => 2,
        ]);

        $this->getJson('api/v1/system/capabilities')->assertUnauthorized();
        $this->getJson('api/v1/system/capabilities')->assertUnauthorized();
        $this->getJson('api/v1/system/capabilities')->assertStatus(429);
    }

    #[Test]
    public function the_privileged_throttle_is_tighter_than_the_liveness_one(): void
    {
        $this->assertLessThan(
            (int) config('sanitube.health.throttle.live.max_attempts'),
            (int) config('sanitube.health.throttle.privileged.max_attempts'),
        );
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

/**
 * Records whether it ran, so liveness can be proven not to run detectors.
 */
final class ExplodingDetector implements CapabilityDetector
{
    public static bool $wasRun = false;

    public function detect(): Capability
    {
        self::$wasRun = true;

        throw new \RuntimeException('a detector ran during a liveness probe');
    }
}
