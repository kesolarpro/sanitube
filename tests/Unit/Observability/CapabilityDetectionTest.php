<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Observability\Capabilities\CapabilityReport;
use SaniTube\Observability\Capabilities\CapabilityStatus;
use SaniTube\Observability\Capabilities\Detectors\FfmpegDetector;
use SaniTube\Observability\Capabilities\Detectors\PhpExtensionsDetector;
use SaniTube\Observability\Capabilities\Detectors\PhpRuntimeDetector;
use SaniTube\Observability\Capabilities\Detectors\QueueDetector;
use SaniTube\Observability\Capabilities\Detectors\SchedulerDetector;
use SaniTube\Observability\SchedulerHeartbeat;
use Tests\TestCase;

final class CapabilityDetectionTest extends TestCase
{
    #[Test]
    public function the_running_php_version_satisfies_the_platform_requirement(): void
    {
        $capability = (new PhpRuntimeDetector)->detect();

        $this->assertNotSame(CapabilityStatus::Unavailable, $capability->status);
    }

    #[Test]
    public function a_missing_extension_is_blocking_and_says_how_to_install_it(): void
    {
        $capability = (new PhpExtensionsDetector(['ctype', 'definitely_not_a_real_extension']))->detect();

        $this->assertSame(CapabilityStatus::Unavailable, $capability->status);
        $this->assertTrue($capability->isBlocking());
        $this->assertStringContainsString('definitely_not_a_real_extension', (string) $capability->detail);
        $this->assertStringContainsString('cPanel', (string) $capability->remediation);
    }

    #[Test]
    public function a_synchronous_queue_is_flagged_as_degraded(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);

        $capability = (new QueueDetector)->detect();

        $this->assertSame(CapabilityStatus::Degraded, $capability->status);
        $this->assertFalse($capability->isBlocking());
    }

    #[Test]
    public function the_portable_database_queue_is_fully_supported(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);

        $capability = (new QueueDetector)->detect();

        $this->assertSame(CapabilityStatus::Available, $capability->status);
    }

    #[Test]
    public function a_missing_ffmpeg_is_optional_not_fatal(): void
    {
        // Ingestion must keep registering assets on a host without FFmpeg.
        config(['media.ffmpeg.path' => '/nonexistent/bin/ffmpeg']);

        $capability = (new FfmpegDetector)->detect();

        $this->assertSame(CapabilityStatus::Optional, $capability->status);
        $this->assertFalse($capability->isBlocking());
        $this->assertFalse($capability->required);
    }

    #[Test]
    public function a_scheduler_that_never_ran_is_blocking(): void
    {
        $heartbeat = new SchedulerHeartbeat(new Repository(new ArrayStore));

        $capability = (new SchedulerDetector($heartbeat))->detect();

        $this->assertSame(CapabilityStatus::Unavailable, $capability->status);
        $this->assertStringContainsString('schedule:run', (string) $capability->remediation);
    }

    #[Test]
    public function a_recent_heartbeat_marks_the_scheduler_healthy(): void
    {
        $heartbeat = new SchedulerHeartbeat(new Repository(new ArrayStore));
        $heartbeat->record();

        $this->assertSame(CapabilityStatus::Available, (new SchedulerDetector($heartbeat))->detect()->status);
    }

    #[Test]
    public function a_stale_heartbeat_is_degraded_rather_than_healthy(): void
    {
        $heartbeat = new SchedulerHeartbeat(new Repository(new ArrayStore));
        $heartbeat->record(new \DateTimeImmutable('-2 hours'));

        $this->assertSame(CapabilityStatus::Degraded, (new SchedulerDetector($heartbeat))->detect()->status);
    }

    #[Test]
    public function an_unreachable_cache_store_does_not_masquerade_as_a_detector_bug(): void
    {
        // On the default database cache store, a dead database makes the
        // heartbeat unreadable. That must surface as "scheduler unverified",
        // leaving the database detector to report the actual cause.
        $heartbeat = new SchedulerHeartbeat(new BrokenCache);

        $capability = (new SchedulerDetector($heartbeat))->detect();

        $this->assertSame(CapabilityStatus::Unavailable, $capability->status);
        $this->assertSame('scheduler', $capability->key);
        $this->assertStringNotContainsString('Detection failed', (string) $capability->detail);
    }

    #[Test]
    public function a_detector_that_throws_is_reported_instead_of_taking_the_report_down(): void
    {
        // The health screen matters most precisely when the environment is broken.
        $registry = new CapabilityRegistry($this->app, [ExplodingDetector::class]);

        $report = $registry->report();

        $this->assertCount(1, $report);
        $this->assertFalse($report->isHealthy());
        $this->assertStringContainsString('Detection failed', (string) $report->all()[0]->detail);
    }

    /**
     * The catch-all is where a credential arrives.
     *
     * `CapabilityRegistry` wraps *every* detector, including the one that
     * resolves object storage — and resolving an S3-compatible provider builds
     * a client from the configured key and secret, so the exception that comes
     * back out is exactly the one that quotes them. A detail is rendered on
     * the System screen, returned by `/api/v1/health/capabilities`, and
     * printed by the doctor, whose output is what somebody pastes into a
     * support thread.
     */
    #[Test]
    public function a_detector_that_throws_holding_a_credential_does_not_publish_it(): void
    {
        config(['filesystems.disks.r2.secret' => 'sk-live-abcdefghijklmnop']);

        $registry = new CapabilityRegistry($this->app, [LeakingDetector::class]);

        $detail = (string) $registry->report()->all()[0]->detail;

        $this->assertStringNotContainsString('sk-live-abcdefghijklmnop', $detail);
        $this->assertStringNotContainsString('X-Amz-Signature', $detail);
        $this->assertStringNotContainsString('bucket.r2.cloudflarestorage.com', $detail);

        // And still says a detector broke, which is the whole point of the
        // catch-all. Scrubbed to nothing would pass every assertion above.
        $this->assertStringContainsString('Detection failed', $detail);
    }

    #[Test]
    public function the_report_separates_blocking_gaps_from_optional_ones(): void
    {
        $report = new CapabilityReport([
            Capability::available('php', 'PHP'),
            Capability::optional('ffmpeg', 'FFmpeg'),
            Capability::degraded('mail', 'Mail'),
            Capability::unavailable('database', 'Database'),
        ]);

        $this->assertFalse($report->isHealthy());
        $this->assertSame(['database'], array_map(
            static fn (Capability $capability): string => $capability->key,
            $report->blocking(),
        ));
        $this->assertSame('FFmpeg', $report->get('ffmpeg')?->label);
        $this->assertNull($report->get('redis'));
    }
}

final class ExplodingDetector implements CapabilityDetector
{
    public function detect(): Capability
    {
        throw new \RuntimeException('disk on fire');
    }
}

/**
 * A detector that fails the way the object-storage one would: holding the
 * request it was making, signature and configured secret and all.
 */
final class LeakingDetector implements CapabilityDetector
{
    public function detect(): Capability
    {
        throw new \RuntimeException(
            'PUT https://bucket.r2.cloudflarestorage.com/probe?X-Amz-Signature=deadbeefcafe failed '
                .'for key sk-live-abcdefghijklmnop',
        );
    }
}

/**
 * A cache store whose backend is down.
 */
final class BrokenCache extends Repository
{
    public function __construct()
    {
        parent::__construct(new ArrayStore);
    }

    /**
     * @param  array<array-key, mixed>|string  $key
     */
    public function get($key, $default = null): mixed
    {
        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }
}
