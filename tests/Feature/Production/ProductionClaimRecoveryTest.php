<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\ClaimProductionSlot;
use SaniTube\Production\Services\OpenProductionSlots;
use SaniTube\Production\Services\ProduceForProductionSlot;
use SaniTube\Production\Services\ReclaimProductionSlots;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * PROD-005 — taking back occasions whose worker never came home.
 *
 * PROD-002 made claiming atomic so two workers cannot both take one occasion.
 * **Nothing made a claim expire.** A process killed between claiming and acting
 * left the occasion CLAIMED for ever, invisible to everything: the scheduler
 * will not re-open a date that already has a slot, and no worker will take one
 * that is not PENDING. PROD-004 named the gap in a docblock and left it.
 *
 * The reaper's whole job is knowing which claims are safe to take, and the
 * question is **not "is this claim old"** — it is **"was anybody asked"**:
 *
 *   1. nobody asked → back to PENDING, and the same run does it properly;
 *   2. a generation exists → not the reaper's business at any age;
 *   3. a supplier was asked, no generation → UNKNOWN_RESULT, never retried.
 *
 * Case 3 is the one that carries the ticket. Retrying it risks paying twice for
 * one occasion, and recording a failure would tell an operator nothing
 * happened, which may be false.
 */
final class ProductionClaimRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        $this->useProvider('primary');

        Queue::fake();
    }

    // ------------------------------------------- the crash, and the recovery

    #[Test]
    public function a_worker_that_dies_before_asking_anybody_loses_the_occasion_for_one_cycle(): void
    {
        // The scenario the ticket is for, start to finish: claim, die, wait out
        // the lease, recover, and do it properly.
        $slot = $this->dueSlot();

        $claimed = $this->app->make(ClaimProductionSlot::class)->claim($slot, 'worker-that-dies');
        $this->assertInstanceOf(ProductionSlot::class, $claimed);
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);

        // Before the lease expires, the occasion is nobody else's.
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $this->reaper()->handle());
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);

        $this->travelPastTheLease();

        $this->assertSame(['reclaimed' => 1, 'unknown' => 0], $this->reaper()->handle());

        $slot->refresh();

        // Available again, and carrying no trace of the worker that lost it --
        // that lives in the audit line instead.
        $this->assertSame(ProductionSlotStatus::Pending, $slot->status);
        $this->assertNull($slot->claimed_by);
        $this->assertNull($slot->claimed_at);

        // And it can now be done properly.
        $generation = $this->producer()->handle($slot->refresh(), 'a-worker-that-lives');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    #[Test]
    public function the_worker_that_lost_it_is_named_in_the_log_because_the_row_no_longer_can_be(): void
    {
        // "Which worker keeps dying" is a question only the log can answer
        // once the claim has been cleared.
        $slot = $this->claimedBy('worker-that-dies');
        $this->travelPastTheLease();

        $this->reaper()->handle();

        $event = AuditEvent::query()
            ->where('action', AuditAction::ProductionOccasionReclaimed->value)
            ->where('subject_uuid', $slot->uuid)
            ->firstOrFail();

        $this->assertSame('worker-that-dies', $event->context['claimed_by'] ?? null);
    }

    // ------------------------------------------- what the reaper will not take

    #[Test]
    public function an_occasion_waiting_on_a_supplier_is_never_taken_however_old_the_claim(): void
    {
        // Case 2. The settler owns this, and a generation that never answers
        // is failed by the poll ceiling -- which brings it back as a settled
        // failure rather than a stuck row.
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'a-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);

        $this->travelPastTheLease();

        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $this->reaper()->handle());

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertSame($generation->getKey(), $slot->music_generation_id);
    }

    #[Test]
    public function an_occasion_that_reached_a_supplier_and_lost_the_answer_is_never_retried(): void
    {
        // **The claim that carries this ticket.** ProduceForProductionSlot
        // writes the supplier's name *before* calling, precisely so this case
        // is visible: somebody was asked and there is nothing to show for it.
        $slot = $this->claimedBy('worker-that-dies-mid-request');

        $slot->forceFill(['attempted_providers' => ['primary']])->save();

        $this->travelPastTheLease();

        $this->assertSame(['reclaimed' => 0, 'unknown' => 1], $this->reaper()->handle());

        $slot->refresh();

        // Not PENDING -- retrying risks paying twice for one occasion.
        // Not FAILED -- "nothing happened" may simply be untrue.
        $this->assertSame(ProductionSlotStatus::Unknown, $slot->status);
        $this->assertSame('WORKER_LOST', $slot->outcome_reason);
        $this->assertNotNull($slot->settled_at);

        // Nothing was generated by the recovery itself.
        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_unknown_result_says_which_suppliers_to_go_and_ask(): void
    {
        $slot = $this->claimedBy('worker-that-dies-mid-request');
        $slot->forceFill(['attempted_providers' => ['primary', 'secondary']])->save();

        $this->travelPastTheLease();
        $this->reaper()->handle();

        $event = AuditEvent::query()
            ->where('action', AuditAction::ProductionOccasionUnknown->value)
            ->where('subject_uuid', $slot->uuid)
            ->firstOrFail();

        // Names only. Whose dashboard to open is operational fact; how to reach
        // them is not, and no endpoint or key is here.
        //
        // A string, not a list. An audit context is a map of named values and
        // Redaction drops entries whose key is not a name -- so a list is
        // stored as nothing at all, which is how the first version of this
        // silently recorded an empty array.
        $this->assertSame('primary, secondary', $event->context['attempted_providers'] ?? null);
    }

    #[Test]
    public function an_unknown_result_is_a_separate_state_from_a_failure(): void
    {
        // §13: UNKNOWN_RESULT must remain distinct from FAILED. They call for
        // different actions -- one is "look at this", the other is "look at
        // this AND check whether you were charged".
        $this->assertNotSame(ProductionSlotStatus::Failed, ProductionSlotStatus::Unknown);
        $this->assertSame('UNKNOWN_RESULT', ProductionSlotStatus::Unknown->value);

        // Settled: nothing further happens on its own.
        $this->assertTrue(ProductionSlotStatus::Unknown->isSettled());
        // And not something a person did, so it stays out of the list of
        // deliberate cancellations.
        $this->assertFalse(ProductionSlotStatus::Unknown->wasSetByAPerson());
    }

    #[Test]
    public function a_settled_occasion_is_not_reclaimed_whatever_its_claim_says(): void
    {
        // A stale `claimed_at` on a finished occasion is ordinary -- settling
        // does not clear it -- and taking it back would resurrect work that
        // already ended.
        $slot = $this->claimedBy('a-worker');
        $this->app->make(ClaimProductionSlot::class)->complete($slot, 'GENERATION_COMPLETED');

        $this->travelPastTheLease();

        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $this->reaper()->handle());
        $this->assertSame(ProductionSlotStatus::Completed, $slot->refresh()->status);
    }

    #[Test]
    public function a_worker_that_was_not_dead_after_all_is_not_declared_unknown(): void
    {
        // The same window again, on the path that matters most. This occasion
        // had already reached a supplier, so the reaper is about to close it as
        // UNKNOWN_RESULT -- a state that asks a person to go and check whether
        // they were charged. Meanwhile the worker, which was merely slow,
        // records the generation it got.
        //
        // Declaring UNKNOWN here would send somebody to a supplier's dashboard
        // over a generation this platform is holding perfectly well, and would
        // settle an occasion that is still running.
        $slot = $this->claimedBy('worker-that-was-merely-slow');
        $slot->forceFill(['attempted_providers' => ['primary']])->save();

        $this->travelPastTheLease();

        $generation = MusicGeneration::query()->create([
            'provider' => 'primary',
            'status' => MusicGenerationStatus::Queued,
            'prompt' => 'the one that got through',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);

        $intruded = false;

        ProductionSlot::retrieved(function (ProductionSlot $model) use (&$intruded, $generation): void {
            if ($intruded) {
                return;
            }

            $intruded = true;

            DB::table('production_slots')
                ->where('id', $model->getKey())
                ->update(['music_generation_id' => $generation->getKey()]);
        });

        $recovered = $this->reaper()->handle();

        ProductionSlot::flushEventListeners();

        $this->assertTrue($intruded);
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $recovered);

        $slot->refresh();

        // Still in flight, and the settler's business now.
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertNull($slot->settled_at);
    }

    // --------------------------------------------------- no double execution

    #[Test]
    public function running_the_reaper_twice_reclaims_once(): void
    {
        $slot = $this->claimedBy('worker-that-dies');
        $this->travelPastTheLease();

        $this->assertSame(['reclaimed' => 1, 'unknown' => 0], $this->reaper()->handle());
        // Idempotent: the second pass finds a PENDING row, which is not its
        // business.
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $this->reaper()->handle());

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', AuditAction::ProductionOccasionReclaimed->value)->count(),
        );
    }

    #[Test]
    public function a_worker_that_takes_the_occasion_back_mid_pass_keeps_it(): void
    {
        // **The actual race**, which arranging rows cannot produce: the
        // collision happens between the reaper's SELECT and its UPDATE, and by
        // then it has already looked. So the conflicting write happens *from
        // inside* that window, on the model's own `retrieved` event, with a raw
        // statement that does not go through Eloquent -- the same shape
        // PROD-002's race test uses, and the only way this guard is reachable
        // in one thread.
        //
        // Without it the reaper would take an occasion a live worker is
        // holding, which on a plan that may act alone means two suppliers asked
        // for one occasion.
        $slot = $this->claimedBy('worker-that-dies');
        $this->travelPastTheLease();

        $intruded = false;

        ProductionSlot::retrieved(function (ProductionSlot $model) use (&$intruded): void {
            if ($intruded) {
                return;
            }

            $intruded = true;

            // Another process re-claims it, freshly, in the gap.
            DB::table('production_slots')
                ->where('id', $model->getKey())
                ->update([
                    'claimed_by' => 'a-worker-that-lives',
                    'claimed_at' => Carbon::now()->toDateTimeString(),
                ]);
        });

        $recovered = $this->reaper()->handle();

        ProductionSlot::flushEventListeners();

        $this->assertTrue($intruded, 'The intrusion must happen inside the window or this proves nothing.');
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $recovered);

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertSame('a-worker-that-lives', $slot->claimed_by);
    }

    #[Test]
    public function a_generation_recorded_mid_pass_stops_the_occasion_being_taken(): void
    {
        // The same window, and the more expensive collision: the worker was not
        // dead after all, and asked a supplier while the reaper was deciding.
        // Reclaiming now would put a paid-for occasion back in the queue.
        $slot = $this->claimedBy('worker-that-seemed-dead');
        $this->travelPastTheLease();

        $generation = MusicGeneration::query()->create([
            'provider' => 'primary',
            'status' => MusicGenerationStatus::Queued,
            'prompt' => 'the one that got through',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);

        $intruded = false;

        ProductionSlot::retrieved(function (ProductionSlot $model) use (&$intruded, $generation): void {
            if ($intruded) {
                return;
            }

            $intruded = true;

            DB::table('production_slots')
                ->where('id', $model->getKey())
                ->update(['music_generation_id' => $generation->getKey()]);
        });

        $recovered = $this->reaper()->handle();

        ProductionSlot::flushEventListeners();

        $this->assertTrue($intruded);
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $recovered);

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertSame($generation->getKey(), $slot->music_generation_id);
    }

    #[Test]
    public function recovery_never_produces_a_second_generation_for_one_occasion(): void
    {
        // The failure the whole lifecycle exists to prevent, asserted end to
        // end: crash after asking, recover, run the producer again.
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'worker-that-dies');

        $this->assertInstanceOf(MusicGeneration::class, $generation);

        $this->travelPastTheLease();
        $this->reaper()->handle();

        // Still claimed and still holding its generation, so the producer
        // cannot take it.
        $this->assertNull($this->producer()->handle($slot->refresh(), 'another-worker'));
        $this->assertSame(1, MusicGeneration::query()->count());
    }

    // ------------------------------------------------------- the lease itself

    #[Test]
    public function a_lease_of_zero_is_refused_rather_than_reclaiming_every_claim_instantly(): void
    {
        // A lease of zero would take a claim back the instant it was made,
        // which is worse than having no reaper at all: two workers on one
        // occasion, and on a plan that may act alone, two suppliers asked.
        config(['production.claim_lease_seconds' => 0]);

        $slot = $this->claimedBy('a-worker');

        $this->assertSame(3600, $this->reaper()->leaseSeconds());
        $this->assertSame(['reclaimed' => 0, 'unknown' => 0], $this->reaper()->handle());
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    #[Test]
    public function the_lease_is_configuration_and_not_a_number_in_the_code(): void
    {
        config(['production.claim_lease_seconds' => 120]);

        $slot = $this->claimedBy('a-worker');

        $this->assertSame(120, $this->reaper()->leaseSeconds());

        Carbon::setTestNow(Carbon::now()->addSeconds(121));

        $this->assertSame(['reclaimed' => 1, 'unknown' => 0], $this->reaper()->handle());
        $this->assertSame(ProductionSlotStatus::Pending, $slot->refresh()->status);
    }

    #[Test]
    public function a_claim_with_no_timestamp_is_malformed_and_recoverable(): void
    {
        // Claiming always writes a `claimed_at`, so a CLAIMED row without one
        // carries no lease at all. Reclaimable for the same reason the others
        // are: nobody was asked, so nothing is at risk.
        $slot = $this->claimedBy('a-worker');
        $slot->forceFill(['claimed_at' => null])->save();

        $this->assertSame(['reclaimed' => 1, 'unknown' => 0], $this->reaper()->handle());
        $this->assertSame(ProductionSlotStatus::Pending, $slot->refresh()->status);
    }

    #[Test]
    public function one_pass_is_bounded_so_a_long_outage_does_not_starve_the_work_it_unblocks(): void
    {
        config(['production.reclaim_batch' => 2]);

        for ($index = 0; $index < 5; $index++) {
            $this->claimedBy('worker-'.$index, daysAgo: $index + 1);
        }

        $this->travelPastTheLease();

        $this->assertSame(2, $this->reaper()->handle()['reclaimed']);
        $this->assertSame(2, $this->reaper()->handle()['reclaimed']);
        $this->assertSame(1, $this->reaper()->handle()['reclaimed']);
        $this->assertSame(0, $this->reaper()->handle()['reclaimed']);
    }

    // ------------------------------------------------------ the production path

    #[Test]
    public function the_run_command_recovers_before_it_starts_anything(): void
    {
        // WHO CALLS THIS IN PRODUCTION: `sanitube:production:run`, registered
        // by ProductionServiceProvider. The reclaim pass runs first, so an
        // occasion abandoned by a dead worker is done properly by the *same*
        // run -- a crash costs one cycle rather than for ever.
        $slot = $this->dueSlot();
        $this->app->make(ClaimProductionSlot::class)->claim($slot, 'worker-that-dies');

        $this->travelPastTheLease();

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('Recovered 1 abandoned occasion(s)')
            ->expectsOutputToContain('Requested 1 generation(s)')
            ->assertSuccessful();

        $this->assertSame(1, MusicGeneration::query()->count());
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    #[Test]
    public function the_command_says_loudly_when_an_answer_was_lost(): void
    {
        // Never folded in with failures: somebody may have been charged for a
        // track this platform has no record of.
        $slot = $this->claimedBy('worker-that-dies-mid-request');
        $slot->forceFill(['attempted_providers' => ['primary']])->save();

        $this->travelPastTheLease();

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('reached a supplier and lost the answer')
            ->assertSuccessful();

        $this->assertSame(ProductionSlotStatus::Unknown, $slot->refresh()->status);
    }

    #[Test]
    public function recovery_still_runs_under_settle_only_because_it_starts_nothing(): void
    {
        $slot = $this->claimedBy('worker-that-dies');
        $this->travelPastTheLease();

        $this->artisan('sanitube:production:run', ['--settle-only' => true])
            ->expectsOutputToContain('Recovered 1 abandoned occasion(s)')
            ->assertSuccessful();

        $this->assertSame(ProductionSlotStatus::Pending, $slot->refresh()->status);
        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function a_paused_platform_recovers_nothing_either(): void
    {
        // Reclaiming is safe, but a paused platform means an operator wants
        // nothing touched -- and a recovered occasion is one a resumed
        // platform immediately acts on.
        $slot = $this->claimedBy('worker-that-dies');
        $this->travelPastTheLease();

        $this->app->make(BackgroundWork::class)->pause(null, 'MAINTENANCE');

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('BACKGROUND_WORK_PAUSED')
            ->assertFailed();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    // --------------------------------------------------------------- helpers

    private function travelPastTheLease(): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($this->reaper()->leaseSeconds() + 60));
    }

    private function reaper(): ReclaimProductionSlots
    {
        return $this->app->make(ReclaimProductionSlots::class);
    }

    private function producer(): ProduceForProductionSlot
    {
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(ProduceForProductionSlot::class);
    }

    private function useProvider(string $name): void
    {
        config([
            'generation.default' => $name,
            'generation.preference' => [$name],
            'generation.providers.'.$name => ['driver' => 'fake'],
        ]);

        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register($name, new FakeMusicGenerationProvider($name));
    }

    private function claimedBy(string $worker, int $daysAgo = 0): ProductionSlot
    {
        $slot = $this->dueSlot($daysAgo);

        $claimed = $this->app->make(ClaimProductionSlot::class)->claim($slot, $worker);

        $this->assertInstanceOf(ProductionSlot::class, $claimed);

        return $claimed;
    }

    private function dueSlot(int $daysAgo = 0): ProductionSlot
    {
        $profile = $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
            'default_artist_id' => Artist::factory()->create()->id,
        ]);

        $plan = $this->app->make(WriteProductionPlan::class)->create($profile, [
            'name' => 'Autumn '.bin2hex(random_bytes(3)),
            'autonomy_mode' => AutonomyMode::AutonomousGeneration,
            'cadence_days' => 7,
            'target_track_count' => 10,
        ]);

        $this->assertInstanceOf(ProductionPlan::class, $plan);

        $opened = $this->app->make(OpenProductionSlots::class)->handle();
        $slot = end($opened);

        $this->assertInstanceOf(ProductionSlot::class, $slot);

        if ($daysAgo > 0) {
            $slot->forceFill(['scheduled_for' => Carbon::now()->subDays($daysAgo)])->save();
        }

        return $slot->refresh();
    }
}
