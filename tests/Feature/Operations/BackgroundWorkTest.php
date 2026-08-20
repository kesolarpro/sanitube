<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Operations\Services\BacklogGuard;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * OPS-002 — the brakes.
 *
 * Two gates in front of the only place that enqueues work in bulk. The failure
 * they exist to prevent is specific: an import enqueues one job per file, a
 * backfill over an existing library would enqueue fifty thousand, and on this
 * platform's baseline — a database queue drained by a cron worker on a shared
 * account — that is not slow, it is how the installation goes down with no
 * remedy but a DELETE somebody should not be running.
 */
final class BackgroundWorkTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // ---------------------------------------------------------- the switch

    #[Test]
    public function an_installation_is_not_paused_until_somebody_pauses_it(): void
    {
        $work = $this->app->make(BackgroundWork::class);

        $this->assertFalse($work->isPaused());

        // Reported from a row created on first read, so an installation that
        // has never been paused still has something to say.
        $this->assertFalse($work->state()->is_paused);
        $this->assertNull($work->state()->paused_at);
    }

    #[Test]
    public function pausing_stops_an_import_from_starting_at_all(): void
    {
        // Refused where somebody is looking at it, rather than half-enqueued
        // into a queue nobody is watching.
        $this->app->make(BackgroundWork::class)->pause($this->operator(), 'INCIDENT');

        try {
            $this->import(['library/a.wav', 'library/b.wav']);
            $this->fail('An import started while background work was paused.');
        } catch (WorkRefused $refused) {
            $this->assertSame('BACKGROUND_WORK_PAUSED', $refused->reason);
        }

        // Nothing half-created: no batch, no items, no jobs.
        $this->assertSame(0, IngestionBatch::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function resuming_lets_work_be_taken_on_again(): void
    {
        $work = $this->app->make(BackgroundWork::class);
        $operator = $this->operator();

        $work->pause($operator, 'INCIDENT');
        $work->resume($operator);

        $batch = $this->import(['library/a.wav']);

        $this->assertSame(1, IngestionBatch::query()->count());
        $this->assertSame(1, $batch->items()->count());
    }

    #[Test]
    public function pausing_twice_keeps_the_first_moment_and_the_first_reason(): void
    {
        // The useful question is when this started. A second press of the same
        // button must not reset the answer.
        $work = $this->app->make(BackgroundWork::class);
        $operator = $this->operator();

        $first = $work->pause($operator, 'INCIDENT');
        $originalMoment = $first->paused_at;

        $second = $work->pause($operator, 'SOMETHING_ELSE');

        $this->assertSame('INCIDENT', $second->reason);
        $this->assertEquals($originalMoment, $second->paused_at);
    }

    #[Test]
    public function both_directions_are_audited_against_the_installation(): void
    {
        // The question after an incident is always when it was pressed and by
        // whom.
        $operator = $this->operator();
        $this->actingAs($operator);

        $work = $this->app->make(BackgroundWork::class);
        $work->pause($operator, 'INCIDENT');
        $work->resume($operator);

        $paused = AuditEvent::query()->where('action', AuditAction::BackgroundWorkPaused->value)->firstOrFail();
        $this->assertSame('INCIDENT', $paused->reason);
        $this->assertSame('system', $paused->subject->value);
        $this->assertSame($operator->id, $paused->actor_id);

        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::BackgroundWorkResumed->value)->count());
    }

    #[Test]
    public function a_pause_that_changes_nothing_is_not_audited_twice(): void
    {
        $work = $this->app->make(BackgroundWork::class);

        $work->pause($this->operator(), 'INCIDENT');
        $work->pause($this->operator(), 'INCIDENT');
        $work->resume($this->operator());
        $work->resume($this->operator());

        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::BackgroundWorkPaused->value)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::BackgroundWorkResumed->value)->count());
    }

    // --------------------------------------------------------- the ceiling

    #[Test]
    public function an_import_larger_than_the_queue_has_room_for_is_refused_whole(): void
    {
        // Half-enqueuing and leaving the remainder to a sweep that may never
        // come is how work gets stranded where nobody is watching.
        config(['operations.backlog.ceiling' => 1]);

        try {
            $this->import(['library/a.wav', 'library/b.wav']);
            $this->fail('An import was accepted past the backlog ceiling.');
        } catch (WorkRefused $refused) {
            $this->assertSame('BACKLOG_SATURATED', $refused->reason);
        }

        $this->assertSame(0, IngestionBatch::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_import_that_fits_is_admitted(): void
    {
        config(['operations.backlog.ceiling' => 5]);

        $this->import(['library/a.wav', 'library/b.wav']);

        $this->assertSame(1, IngestionBatch::query()->count());
    }

    #[Test]
    public function a_ceiling_of_zero_is_no_ceiling(): void
    {
        // A legitimate setting on a machine with a real worker.
        config(['operations.backlog.ceiling' => 0]);

        $guard = $this->app->make(BacklogGuard::class);

        $this->assertSame(0, $guard->ceiling());
        $this->assertFalse($guard->wouldExceedCeiling(1_000_000));
    }

    #[Test]
    public function a_queue_that_cannot_report_its_size_admits_rather_than_refuses(): void
    {
        // Null is not zero. A driver that cannot answer must not read as an
        // empty queue -- but nor may the ceiling break a configuration this
        // platform is meant to support. It is a safeguard, not a correctness
        // requirement.
        $guard = new BacklogGuard(new class implements Factory
        {
            public function connection($name = null): \Illuminate\Contracts\Queue\Queue
            {
                throw new \RuntimeException('this driver does not report a size');
            }
        });

        config(['operations.backlog.ceiling' => 1]);

        $this->assertNull($guard->depth());
        $this->assertFalse($guard->wouldExceedCeiling(500));
    }

    // ------------------------------------------------------------- console

    #[Test]
    public function the_console_can_pause_and_resume_when_the_interface_cannot(): void
    {
        // The moment an operator most needs this is the moment the interface
        // is the thing that is struggling.
        $this->artisan('sanitube:work:pause', ['--reason' => 'INCIDENT'])->assertSuccessful();
        $this->assertTrue($this->app->make(BackgroundWork::class)->isPaused());

        $this->artisan('sanitube:work:status')->assertSuccessful();

        $this->artisan('sanitube:work:resume')->assertSuccessful();
        $this->assertFalse($this->app->make(BackgroundWork::class)->isPaused());
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @param  list<string>  $references
     */
    private function import(array $references): IngestionBatch
    {
        foreach ($references as $reference) {
            $this->store->put($reference, 'the recording '.$reference);
        }

        return $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            references: $references,
        );
    }

    private function operator(): User
    {
        $user = User::query()->create([
            'name' => 'An operator',
            'email' => 'ops-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
