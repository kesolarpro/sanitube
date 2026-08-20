<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\ClaimProductionSlot;
use SaniTube\Production\Services\OpenProductionSlots;
use SaniTube\Production\Services\WriteProductionPlan;
use Tests\TestCase;

/**
 * PROD-002 — an occasion, and the fact that opening one is not doing it.
 *
 * **The scheduler must never invoke music generation.** That is the constraint
 * the whole ticket is built around, and it is not fussiness: a scheduler that
 * generated directly would be a cron entry that spends money in the dark.
 * Nothing would record that the occasion existed, nothing could cancel it
 * before it ran, a second run would do the work twice, and the only evidence
 * afterwards would be a bill and some audio.
 *
 * What the indirection buys is exactly the four properties §17 asks for:
 * idempotent, traceable, cancelable, auditable. Each has a test below.
 */
final class ProductionSlotTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------- the scheduler generates nothing

    #[Test]
    public function the_scheduler_never_reaches_generation(): void
    {
        // Asserted mechanically, because this is a boundary that erodes by
        // somebody adding one convenient call. A grep is a blunt instrument
        // and exactly right here: the scheduler has no business naming the
        // generation module at all.
        foreach ([
            base_path('src/Production/Services/OpenProductionSlots.php'),
            base_path('src/Production/Console/OpenProductionSlotsCommand.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('MusicGeneration', $source, basename($file));
            $this->assertStringNotContainsString('GenerationProject', $source, basename($file));
        }
    }

    #[Test]
    public function opening_slots_queues_no_work_and_spends_nothing(): void
    {
        // The behavioural half of the same claim. Even for a plan set to the
        // most autonomous mode that can exist.
        Queue::fake();

        $this->plan(AutonomyMode::AutonomousPreparation, cadence: 7);

        $opened = $this->scheduler()->handle();

        $this->assertCount(1, $opened);
        $this->assertSame(ProductionSlotStatus::Pending, $opened[0]->status);

        Queue::assertNothingPushed();
        $this->assertSame(0, MusicGeneration::query()->count());
    }

    // ------------------------------------------------------------ idempotent

    #[Test]
    public function running_the_scheduler_twice_opens_one_occasion(): void
    {
        // A cron that overlapped, an operator who pressed the button, a deploy
        // that restarted it. All three happen, and all three must produce one
        // slot.
        $this->plan(cadence: 7);

        $this->assertCount(1, $this->scheduler()->handle());
        $this->assertCount(0, $this->scheduler()->handle());
        $this->assertCount(0, $this->scheduler()->handle());

        $this->assertSame(1, ProductionSlot::query()->count());
    }

    #[Test]
    public function the_database_is_what_enforces_one_occasion_per_day(): void
    {
        // Checking first and then inserting is "usually once": two processes
        // can both find nothing and both insert. So the unique key is the
        // guard, and this asserts it exists rather than trusting the service
        // that respects it.
        $plan = $this->plan(cadence: 7);
        $date = Carbon::now()->startOfDay();

        ProductionSlot::query()->create([
            'production_plan_id' => $plan->id,
            'scheduled_for' => $date,
            'status' => ProductionSlotStatus::Pending,
            'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductionSlot::query()->create([
            'production_plan_id' => $plan->id,
            'scheduled_for' => $date,
            'status' => ProductionSlotStatus::Pending,
            'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
        ]);
    }

    #[Test]
    public function an_occasion_that_already_exists_is_not_opened_again(): void
    {
        // Written first as a "race" test, which it is not: the scheduler sees
        // the existing slot when it works out the next date and never reaches
        // the insert, so it exercised the cadence and nothing else. Renamed to
        // what it actually asserts, which is worth asserting.
        $plan = $this->plan(cadence: 7);

        ProductionSlot::query()->create([
            'production_plan_id' => $plan->id,
            'scheduled_for' => Carbon::now()->startOfDay(),
            'status' => ProductionSlotStatus::Pending,
            'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
        ]);

        $this->assertSame([], $this->scheduler()->handle());
        $this->assertSame(1, ProductionSlot::query()->count());
    }

    #[Test]
    public function a_real_race_on_the_same_occasion_is_success_rather_than_a_crash(): void
    {
        // **The actual race**, which a single-threaded test cannot produce by
        // arranging rows: the collision happens between the scheduler working
        // out the date and inserting it, and by then it has already looked.
        //
        // So the conflicting row is inserted *from inside* that window, on the
        // model's own creating event, with a raw statement that does not go
        // through Eloquent. That is exactly what a second process does, and it
        // is the only way this catch is reachable in one thread.
        $plan = $this->plan(cadence: 7);
        $intruded = false;

        ProductionSlot::creating(function () use ($plan, &$intruded): void {
            if ($intruded) {
                return;
            }

            $intruded = true;

            DB::table('production_slots')->insert([
                'uuid' => (string) Str::uuid7(),
                'production_plan_id' => $plan->id,
                // The same format Eloquent writes, and that detail is not
                // incidental. Laravel stores a `date` cast through the
                // connection's datetime format, so a writer that inserts a
                // bare `Y-m-d` produces a different *string* -- and on
                // SQLite's dynamic typing a different string is a different
                // value, so the unique key does not fire. MySQL and MariaDB
                // coerce both to the same DATE and it does.
                //
                // Written the short way first, and this test passed while
                // proving nothing. See the migration's note.
                'scheduled_for' => Carbon::now()->startOfDay(),
                'status' => ProductionSlotStatus::Pending->value,
                'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        try {
            // No exception, and nothing reported as opened by *this* run --
            // because it did not open it.
            $this->assertSame([], $this->scheduler()->handle());
        } finally {
            ProductionSlot::flushEventListeners();
        }

        $this->assertTrue($intruded, 'The race really did happen.');
        $this->assertSame(1, ProductionSlot::query()->count());
    }

    // ------------------------------------------------------------- cadence

    #[Test]
    public function a_cadence_is_counted_from_the_last_occasion(): void
    {
        // Not from the plan's creation. A scheduler that answered "how many
        // should there have been by now" would turn a fortnight's pause into a
        // fortnight's backlog.
        $plan = $this->plan(cadence: 7);
        $scheduler = $this->scheduler();

        $first = $scheduler->handle(Carbon::parse('2026-03-01'));
        $this->assertCount(1, $first);

        // Six days later: not yet.
        $this->assertSame([], $scheduler->handle(Carbon::parse('2026-03-07')));

        // Seven days later: due.
        $second = $scheduler->handle(Carbon::parse('2026-03-08'));
        $this->assertCount(1, $second);
        $this->assertSame('2026-03-08', $second[0]->scheduled_for->toDateString());
    }

    #[Test]
    public function a_plan_that_fell_behind_catches_up_one_occasion_at_a_time(): void
    {
        // Rather than opening every date it missed at once. A plan resumed
        // after two months should not arrive owing eight slots.
        $plan = $this->plan(cadence: 7);
        $scheduler = $this->scheduler();

        $scheduler->handle(Carbon::parse('2026-01-01'));

        $late = $scheduler->handle(Carbon::parse('2026-03-01'));

        $this->assertCount(1, $late);
        $this->assertSame('2026-03-01', $late[0]->scheduled_for->toDateString());
        $this->assertSame(2, ProductionSlot::query()->count());
    }

    #[Test]
    public function a_plan_with_no_cadence_opens_nothing(): void
    {
        // A standing intention with no rhythm is a plan somebody drives by
        // hand, which is a legitimate way to run one.
        $this->plan(cadence: null);

        $this->assertSame([], $this->scheduler()->handle());
    }

    #[Test]
    public function only_an_active_plan_opens_occasions(): void
    {
        $planner = $this->planner();

        foreach ([
            ProductionPlanStatus::Paused,
            ProductionPlanStatus::Exhausted,
            ProductionPlanStatus::ReviewRequired,
            ProductionPlanStatus::Disabled,
        ] as $status) {
            $plan = $this->plan(cadence: 7, name: 'Plan '.$status->value);
            $plan->forceFill(['status' => $status])->save();
        }

        $this->assertSame([], $this->scheduler()->handle());
        $this->assertSame(0, ProductionSlot::query()->count());

        $planner->resume(ProductionPlan::query()->where('status', ProductionPlanStatus::Paused->value)->firstOrFail());

        $this->assertCount(1, $this->scheduler()->handle());
    }

    // ------------------------------------------------------------- claiming

    #[Test]
    public function a_slot_is_claimed_exactly_once(): void
    {
        // Two workers draining the same queue will both find the same pending
        // slot. Only the atomic update decides which gets it -- and this
        // platform's whole reason for having slots is that occasions happen
        // once.
        $slot = $this->openOne();
        $claimer = $this->claimer();

        $first = $claimer->claim($slot, 'worker-a');
        $second = $claimer->claim($slot->fresh() ?? $slot, 'worker-b');

        $this->assertInstanceOf(ProductionSlot::class, $first);
        $this->assertNull($second, 'A second worker must not also claim it.');
        $this->assertSame('worker-a', $slot->fresh()?->claimed_by);
    }

    #[Test]
    public function a_stale_reader_does_not_win_the_claim(): void
    {
        // The loser of a real race holds an in-memory row saying PENDING while
        // the table says otherwise. A read-then-write would take it anyway.
        $slot = $this->openOne();
        $stale = ProductionSlot::query()->findOrFail($slot->id);

        $this->claimer()->claim($slot, 'worker-a');

        $this->assertSame(ProductionSlotStatus::Pending, $stale->status, 'The copy is deliberately stale.');
        $this->assertNull($this->claimer()->claim($stale, 'worker-b'));
        $this->assertSame('worker-a', $slot->fresh()?->claimed_by);
    }

    #[Test]
    public function a_slot_scheduled_for_later_is_not_work_waiting(): void
    {
        // A worker that ignored the date would do a month of production in an
        // afternoon.
        $plan = $this->plan(cadence: 7);

        $future = ProductionSlot::query()->create([
            'production_plan_id' => $plan->id,
            'scheduled_for' => Carbon::now()->addWeek(),
            'status' => ProductionSlotStatus::Pending,
            'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
        ]);

        $this->assertFalse($future->isDue());
        $this->assertNull($this->claimer()->claim($future, 'worker-a'));
        $this->assertSame(ProductionSlotStatus::Pending, $future->fresh()?->status);
    }

    // ------------------------------------------------------------- settling

    #[Test]
    public function skipped_is_not_failed(): void
    {
        // An inventory that found enough already is the system working. A
        // screen filing those under failures would teach an operator to ignore
        // the failure list.
        $claimer = $this->claimer();

        $skipped = $claimer->skip($this->claimed('worker-a'), 'ENOUGH_ON_HAND');
        $failed = $claimer->fail($this->claimed('worker-b', name: 'Other'), 'PROVIDER_UNAVAILABLE');

        $this->assertSame(ProductionSlotStatus::Skipped, $skipped->status);
        $this->assertSame(ProductionSlotStatus::Failed, $failed->status);
        $this->assertNotSame($skipped->status, $failed->status);

        foreach ([$skipped, $failed] as $slot) {
            $this->assertNotNull($slot->outcome_reason);
            $this->assertNotNull($slot->settled_at);
            $this->assertTrue($slot->status->isSettled());
        }
    }

    #[Test]
    public function a_person_may_call_off_an_occasion_a_worker_has_already_taken(): void
    {
        // A worker holding a slot and waiting on a provider is exactly the
        // situation somebody wants to call off, and a cancel that only worked
        // before anything picked it up would be a cancel that never works when
        // it matters.
        $claimed = $this->claimed('worker-a');

        $cancelled = $this->claimer()->cancel($claimed, 'CHANGED_OUR_MIND');

        $this->assertInstanceOf(ProductionSlot::class, $cancelled);
        $this->assertSame(ProductionSlotStatus::Cancelled, $cancelled->status);
        $this->assertTrue($cancelled->status->wasSetByAPerson());
    }

    #[Test]
    public function a_settled_occasion_is_not_re_settled(): void
    {
        // Re-settling would rewrite how it actually ended.
        $claimer = $this->claimer();
        $completed = $claimer->complete($this->claimed('worker-a'), 'MADE_SOMETHING');

        $this->assertNull($claimer->cancel($completed));
        $this->assertSame(ProductionSlotStatus::Completed, $completed->fresh()->status);
        $this->assertSame('MADE_SOMETHING', $completed->fresh()->outcome_reason);
    }

    #[Test]
    public function a_cancelled_occasion_cannot_then_be_claimed(): void
    {
        $slot = $this->openOne();
        $cancelled = $this->claimer()->cancel($slot, 'CHANGED_OUR_MIND');

        $this->assertInstanceOf(ProductionSlot::class, $cancelled);
        $this->assertNull($this->claimer()->claim($cancelled, 'worker-a'));
    }

    // -------------------------------------------------------- the command

    #[Test]
    public function the_command_opens_rows_and_says_it_spent_nothing(): void
    {
        // "Opened 3 slots" reads as "made three tracks" to anybody who has not
        // read the scheduler, so the command says otherwise out loud.
        Queue::fake();
        $this->plan(cadence: 7);

        $this->artisan('sanitube:production:open-slots')
            ->expectsOutputToContain('Opened 1 slot(s)')
            ->expectsOutputToContain('Nothing has been generated')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_command_stops_when_the_platform_is_paused(): void
    {
        // A scheduler that kept opening work during a pause would hand the
        // queue a backlog the moment somebody resumed, which is the opposite
        // of what pausing is for.
        $this->plan(cadence: 7);
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $this->artisan('sanitube:production:open-slots')
            ->expectsOutputToContain('BACKGROUND_WORK_PAUSED')
            ->assertFailed();

        $this->assertSame(0, ProductionSlot::query()->count());
    }

    #[Test]
    public function a_dry_run_opens_nothing_and_admits_it_can_report_nothing(): void
    {
        // Honest rather than helpful. Reporting a count would mean running the
        // scheduler and rolling back, and a rollback racing another process
        // would report a slot that then really existed.
        $this->plan(cadence: 7);

        $this->artisan('sanitube:production:open-slots --dry-run')
            ->expectsOutputToContain('Nothing was opened')
            ->assertSuccessful();

        $this->assertSame(0, ProductionSlot::query()->count());
    }

    // ------------------------------------------------------------ fixtures

    private function scheduler(): OpenProductionSlots
    {
        return $this->app->make(OpenProductionSlots::class);
    }

    private function claimer(): ClaimProductionSlot
    {
        return $this->app->make(ClaimProductionSlot::class);
    }

    private function planner(): WriteProductionPlan
    {
        return $this->app->make(WriteProductionPlan::class);
    }

    private function profile(): EditorialProfile
    {
        return $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
        ]);
    }

    private function plan(
        AutonomyMode $mode = AutonomyMode::Manual,
        ?int $cadence = 7,
        string $name = 'Autumn',
    ): ProductionPlan {
        return $this->planner()->create($this->profile(), [
            'name' => $name.' '.bin2hex(random_bytes(3)),
            'autonomy_mode' => $mode,
            'cadence_days' => $cadence,
        ]);
    }

    private function openOne(): ProductionSlot
    {
        $this->plan(cadence: 7);

        $opened = $this->scheduler()->handle();

        $this->assertCount(1, $opened);

        return $opened[0];
    }

    private function claimed(string $worker, string $name = 'Autumn'): ProductionSlot
    {
        $this->plan(cadence: 7, name: $name);

        $opened = $this->scheduler()->handle();
        $slot = end($opened);

        $this->assertInstanceOf(ProductionSlot::class, $slot);

        $claimed = $this->claimer()->claim($slot, $worker);

        $this->assertInstanceOf(ProductionSlot::class, $claimed);

        return $claimed;
    }
}
