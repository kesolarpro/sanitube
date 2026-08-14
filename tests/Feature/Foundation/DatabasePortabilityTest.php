<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Observability\Capabilities\CapabilityStatus;
use SaniTube\Observability\Capabilities\Detectors\DatabaseDetector;
use SaniTube\Observability\SchedulerHeartbeat;
use Tests\TestCase;

/**
 * Exercises the schema against whichever engine the run is pointed at.
 *
 * CI runs this file four times — SQLite, MySQL 8.0, MariaDB 10.6 and MariaDB
 * 11.4 — because cPanel, the primary deployment target, ships MariaDB, and
 * MariaDB and MySQL disagree about index key length, CHECK constraints, JSON
 * and utf8mb4 collations. Catching that here is far cheaper than catching it
 * on someone's shared hosting account.
 */
final class DatabasePortabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migrations_produce_the_tables_the_platform_depends_on(): void
    {
        foreach (['users', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Missing table [%s].', $table));
        }
    }

    #[Test]
    public function four_byte_utf8_survives_a_round_trip(): void
    {
        // Track and artist names are international by definition. An engine
        // configured with three-byte utf8 silently mangles or truncates these,
        // and the damage is only visible once it reaches a DSP.
        $name = 'Café 東京 Ólafur 🎵';

        DB::table('users')->insert([
            'name' => $name,
            'email' => 'utf8@example.test',
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($name, DB::table('users')->where('email', 'utf8@example.test')->value('name'));
    }

    #[Test]
    public function the_database_queue_accepts_and_returns_a_payload(): void
    {
        // The portable default queue driver is only portable if every engine
        // can hold a job payload — including one with multi-byte content.
        $payload = json_encode(['displayName' => 'IngestTrack', 'title' => 'Ámbar 🎶'], JSON_THROW_ON_ERROR);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $stored = DB::table('jobs')->where('queue', 'default')->value('payload');

        $this->assertSame($payload, $stored);
        $this->assertSame('Ámbar 🎶', json_decode((string) $stored, true)['title']);
    }

    #[Test]
    public function the_scheduler_heartbeat_round_trips_through_the_database_cache_store(): void
    {
        // In production the cache store is the database, and the heartbeat is
        // what proves the cron entry exists. If it cannot persist, a missing
        // crontab stays invisible.
        config(['cache.default' => 'database']);

        $heartbeat = new SchedulerHeartbeat($this->app->make(Factory::class)->store('database'));
        $heartbeat->record();

        $this->assertNotNull($heartbeat->lastRunAt());
    }

    #[Test]
    public function the_capability_detector_recognises_the_engine_under_test(): void
    {
        $capability = (new DatabaseDetector($this->app->make(DatabaseManager::class)))->detect();

        $this->assertNotSame(
            CapabilityStatus::Unavailable,
            $capability->status,
            'The database detector could not connect: '.$capability->detail,
        );

        // MySQL and MariaDB are production-grade; SQLite is reported as
        // degraded on purpose, so both outcomes are correct here.
        $expected = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? CapabilityStatus::Available
            : CapabilityStatus::Degraded;

        $this->assertSame($expected, $capability->status);
    }
}
