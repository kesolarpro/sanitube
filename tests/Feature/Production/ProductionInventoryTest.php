<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\ProductionInventory;
use SaniTube\Production\Services\TakeProductionInventory;
use SaniTube\Production\Services\WorkProductionSlot;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * PROD-003 — do not generate blindly.
 *
 * **The count that stops a plan double-ordering.** Autonomous generation
 * without an inventory is a machine that produces forty tracks because forty is
 * the number in the plan, regardless of the thirty-eight already sitting
 * unreleased — and every one of those forty is a paid call to somebody else's
 * server.
 *
 * The two mistakes this file exists to prevent, in order of how expensive they
 * are:
 *
 *   - **not counting work in flight**, which orders more every single run;
 *   - **reporting zero when the answer is unknown**, which invites generating
 *     on exactly the installations that cannot tell what they have.
 */
final class ProductionInventoryTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------------- in flight

    #[Test]
    public function work_already_asked_for_is_counted(): void
    {
        // **The half everyone forgets.** An occasion already committed has
        // nothing to show for it, so a count that looked only at what exists
        // would order more every time it ran -- turning a plan that wants ten
        // tracks into one that has ordered a hundred, all correctly, one slot
        // at a time.
        $plan = $this->plan(target: 3);

        $this->assertSame(0, $this->inventory($plan)->inFlight);

        $this->openSlot($plan);
        $this->openSlot($plan, days: 7);
        $this->openSlot($plan, days: 14);

        $taken = $this->inventory($plan);

        $this->assertSame(3, $taken->inFlight);
        $this->assertSame(0, $taken->needed());
        $this->assertFalse($taken->wantsMore());
    }

    #[Test]
    public function a_settled_occasion_stops_being_in_flight(): void
    {
        // Otherwise a plan whose slots were all cancelled would never produce
        // again.
        $plan = $this->plan(target: 1);
        $slot = $this->openSlot($plan);

        $this->assertFalse($this->inventory($plan)->wantsMore());

        $slot->forceFill(['status' => ProductionSlotStatus::Cancelled])->save();

        $this->assertTrue($this->inventory($plan)->wantsMore());
    }

    // ---------------------------------------------------------- on hand

    #[Test]
    public function unreleased_tracks_count_and_released_ones_do_not(): void
    {
        // A released track is spent -- it did its job. Counting it would make
        // a plan stop producing because it once had enough rather than because
        // it has enough, and a plan that released everything would then never
        // produce again.
        $plan = $this->plan(target: 4);
        $artist = $this->artistFor($plan);

        // Available.
        $this->trackFor($artist, TrackStatus::Draft);

        // Spent: already delivered.
        $this->trackFor($artist, TrackStatus::Released);

        // **The case the release filter actually guards**, and the one the
        // status filter does not: a draft track already committed to a release
        // somebody is assembling. It is not available stock, and counting it
        // would let the same track satisfy two plans -- or satisfy a plan
        // twice, once as stock and once as a release.
        //
        // Written first with a *released* track attached, which the status
        // filter excluded anyway, so the test passed while proving nothing
        // about this filter. A mutation is what showed that.
        $committed = $this->trackFor($artist, TrackStatus::Draft);
        $committed->releases()->attach(Release::factory()->create()->id, [
            'disc_number' => 1,
            'track_number' => 1,
        ]);

        $taken = $this->inventory($plan);

        $this->assertSame(1, $taken->onHand);
        $this->assertSame(3, $taken->needed());
    }

    #[Test]
    public function only_tracks_credited_to_this_imprints_artist_count(): void
    {
        // A count across the whole catalogue would let one imprint's stock
        // satisfy another's target, which is how two plans end up sharing a
        // backlog neither of them can see.
        $plan = $this->plan(target: 2);
        $mine = $this->artistFor($plan);
        $theirs = Artist::factory()->create();

        $this->trackFor($mine, TrackStatus::Draft);
        $this->trackFor($theirs, TrackStatus::Draft);
        $this->trackFor($theirs, TrackStatus::Draft);

        $this->assertSame(1, $this->inventory($plan)->onHand);
    }

    #[Test]
    public function a_featured_credit_is_not_this_imprints_stock(): void
    {
        // A guest appearance on somebody else's track is not something this
        // plan can release. Counting it would make an imprint look like it had
        // an album's worth of material it does not own the primary credit for.
        $plan = $this->plan(target: 2);
        $artist = $this->artistFor($plan);

        $this->trackFor($artist, TrackStatus::Draft);

        foreach ([TrackArtistRole::Featured, TrackArtistRole::Remixer, TrackArtistRole::With] as $role) {
            $guest = Track::factory()->create(['status' => TrackStatus::Draft]);
            $guest->artists()->attach($artist->id, ['role' => $role->value, 'position' => 2]);
        }

        $this->assertSame(1, $this->inventory($plan)->onHand);
    }

    // ----------------------------------------------- unknown is not zero

    #[Test]
    public function a_plan_whose_imprint_names_no_artist_reports_that_it_cannot_tell(): void
    {
        // **Zero and "I cannot tell" look identical in a number and mean
        // opposite things.** Zero invites generating; this must not.
        $profile = $this->app->make(WriteEditorialProfile::class)->create(['name' => 'Anonymous']);
        $plan = $this->planner()->create($profile, [
            'name' => 'Autumn',
            'target_track_count' => 10,
            'autonomy_mode' => AutonomyMode::AutonomousGeneration,
        ]);

        $taken = $this->inventory($plan);

        $this->assertFalse($taken->attributable);
        $this->assertNull($taken->needed(), 'An unanswerable question has no number.');
        $this->assertFalse($taken->wantsMore());
        $this->assertFalse($taken->targetReached());
        $this->assertSame('NO_ATTRIBUTION', $taken->refusal());
    }

    #[Test]
    public function not_knowing_outranks_every_other_answer(): void
    {
        // Every other answer is a number this one says is meaningless.
        $unknown = new ProductionInventory(onHand: 0, inFlight: 0, target: null, attributable: false);

        $this->assertSame('NO_ATTRIBUTION', $unknown->refusal());
    }

    // ------------------------------------------------------- no target

    #[Test]
    public function a_plan_with_no_target_never_wants_more_on_its_own(): void
    {
        // An unstated intention is not a licence to produce indefinitely. A
        // plan that wants unattended generation can say how much it wants.
        $plan = $this->plan(target: null);

        $taken = $this->inventory($plan);

        $this->assertNull($taken->needed(), 'Null target means null need, not unlimited need.');
        $this->assertFalse($taken->wantsMore());
        $this->assertSame('NO_TARGET', $taken->refusal());
    }

    // ----------------------------------------------------- the arithmetic

    #[Test]
    public function needed_is_the_target_less_what_exists_and_what_is_coming(): void
    {
        $taken = new ProductionInventory(onHand: 3, inFlight: 2, target: 10);

        $this->assertSame(5, $taken->needed());
        $this->assertTrue($taken->wantsMore());
        $this->assertNull($taken->refusal());
    }

    #[Test]
    public function a_plan_that_overshot_is_not_owed_negative_work(): void
    {
        $taken = new ProductionInventory(onHand: 12, inFlight: 1, target: 10);

        $this->assertSame(0, $taken->needed());
        $this->assertTrue($taken->targetReached());
        $this->assertSame('TARGET_REACHED', $taken->refusal());
    }

    #[Test]
    public function the_target_is_reached_by_what_exists_and_not_by_what_is_coming(): void
    {
        // Work in flight might fail. Marking a plan finished on the strength
        // of it would leave a target the plan never reaches with nothing
        // running to reach it.
        $taken = new ProductionInventory(onHand: 4, inFlight: 6, target: 10);

        $this->assertFalse($taken->targetReached());
        $this->assertSame('ENOUGH_IN_FLIGHT', $taken->refusal());
    }

    // -------------------------------------------------------- the worker

    #[Test]
    public function the_worker_counts_before_it_decides_anything(): void
    {
        // The claim this ticket exists for. A worker that claimed and
        // generated would be blind generation with an extra table in front of
        // it.
        $plan = $this->plan(target: 5, mode: AutonomyMode::AutonomousGeneration);
        $slot = $this->openSlot($plan);

        $justified = $this->worker()->handle($slot, 'worker-a');

        $this->assertInstanceOf(ProductionInventory::class, $justified);
        $this->assertTrue($justified->wantsMore());

        // Still claimed: what happens next is generation, and completing here
        // would record that something was made before anything was.
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->fresh()->status);
    }

    #[Test]
    public function the_worker_skips_rather_than_generating_when_there_is_enough(): void
    {
        Queue::fake();

        $plan = $this->plan(target: 1, mode: AutonomyMode::AutonomousGeneration);
        $artist = $this->artistFor($plan);
        $this->trackFor($artist, TrackStatus::Draft);

        $slot = $this->openSlot($plan);

        $this->assertNull($this->worker()->handle($slot, 'worker-a'));

        $settled = $slot->fresh();

        $this->assertInstanceOf(ProductionSlot::class, $settled);
        $this->assertSame(ProductionSlotStatus::Skipped, $settled->status);
        $this->assertSame('TARGET_REACHED', $settled->outcome_reason);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_worker_will_not_act_alone_on_a_plan_that_did_not_grant_it(): void
    {
        // The occasion is still real -- a person can act on it -- so it is
        // skipped rather than failed.
        $plan = $this->plan(target: 5, mode: AutonomyMode::Assisted);
        $slot = $this->openSlot($plan);

        $this->assertNull($this->worker()->handle($slot, 'worker-a'));
        $this->assertSame('AUTONOMY_NOT_GRANTED', $slot->fresh()->outcome_reason);
    }

    #[Test]
    public function a_plan_stopped_while_the_slot_waited_is_noticed_by_the_worker(): void
    {
        // A queue is a delay, and this is the last point at which noticing is
        // free rather than billed.
        $plan = $this->plan(target: 5, mode: AutonomyMode::AutonomousGeneration);
        $slot = $this->openSlot($plan);

        $this->planner()->pause($plan);

        $this->assertNull($this->worker()->handle($slot, 'worker-a'));
        $this->assertSame(ProductionSlotStatus::Skipped, $slot->fresh()->status);
        $this->assertSame('PLAN_NOT_RUNNABLE', $slot->fresh()->outcome_reason);
    }

    #[Test]
    public function a_worker_that_cannot_tell_what_the_plan_has_does_not_generate(): void
    {
        // The most important refusal here. An installation whose imprint names
        // no artist would otherwise read "zero on hand" and produce forever.
        $profile = $this->app->make(WriteEditorialProfile::class)->create(['name' => 'Anonymous']);
        $plan = $this->planner()->create($profile, [
            'name' => 'Autumn',
            'target_track_count' => 10,
            'cadence_days' => 7,
            'autonomy_mode' => AutonomyMode::AutonomousGeneration,
        ]);

        $slot = $this->openSlot($plan);

        $this->assertNull($this->worker()->handle($slot, 'worker-a'));
        $this->assertSame('NO_ATTRIBUTION', $slot->fresh()->outcome_reason);
    }

    #[Test]
    public function two_workers_cannot_both_be_justified_by_one_occasion(): void
    {
        $plan = $this->plan(target: 5, mode: AutonomyMode::AutonomousGeneration);
        $slot = $this->openSlot($plan);
        $worker = $this->worker();

        $this->assertInstanceOf(ProductionInventory::class, $worker->handle($slot, 'worker-a'));
        $this->assertNull($worker->handle($slot->fresh() ?? $slot, 'worker-b'));
    }

    #[Test]
    public function the_worker_generates_nothing_itself(): void
    {
        // It decides that generating is warranted and says so. Producing the
        // audio is the next thing along, and keeping the decision separate
        // from the doing is what makes "why did the platform make this"
        // answerable from a row rather than from a log.
        $source = (string) file_get_contents(base_path('src/Production/Services/WorkProductionSlot.php'));

        $this->assertStringNotContainsString('MusicGeneration', $source);
        $this->assertStringNotContainsString('dispatch', $source);
    }

    // ------------------------------------------------------------ fixtures

    private function inventory(ProductionPlan $plan): ProductionInventory
    {
        return $this->app->make(TakeProductionInventory::class)->handle($plan->fresh() ?? $plan);
    }

    private function worker(): WorkProductionSlot
    {
        return $this->app->make(WorkProductionSlot::class);
    }

    private function planner(): WriteProductionPlan
    {
        return $this->app->make(WriteProductionPlan::class);
    }

    private function plan(
        ?int $target,
        AutonomyMode $mode = AutonomyMode::Manual,
    ): ProductionPlan {
        $artist = Artist::factory()->create();

        $profile = $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
            'default_artist_id' => $artist->id,
        ]);

        return $this->planner()->create($profile, [
            'name' => 'Autumn '.bin2hex(random_bytes(3)),
            'target_track_count' => $target,
            'cadence_days' => 7,
            'autonomy_mode' => $mode,
        ]);
    }

    private function artistFor(ProductionPlan $plan): Artist
    {
        $profile = $plan->editorialProfile;

        $this->assertInstanceOf(EditorialProfile::class, $profile);

        $artist = $profile->defaultArtist;

        $this->assertInstanceOf(Artist::class, $artist);

        return $artist;
    }

    private function trackFor(Artist $artist, TrackStatus $status): Track
    {
        $track = Track::factory()->create(['status' => $status]);

        $track->artists()->attach($artist->id, [
            'role' => TrackArtistRole::Primary->value,
            'position' => 1,
        ]);

        return $track;
    }

    private function openSlot(ProductionPlan $plan, int $days = 0): ProductionSlot
    {
        return ProductionSlot::query()->create([
            'production_plan_id' => $plan->id,
            'scheduled_for' => now()->startOfDay()->subDays($days),
            'status' => ProductionSlotStatus::Pending,
            'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
        ]);
    }
}
