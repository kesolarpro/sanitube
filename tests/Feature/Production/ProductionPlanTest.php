<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Exceptions\ProductionPlanException;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Services\WriteProductionPlan;
use Tests\TestCase;

/**
 * PROD-001 — a body of work, and how far the platform may carry it alone.
 *
 * Two claims, and the second is the one that matters most in the whole
 * platform:
 *
 *   - **Autonomy belongs to the plan**, not to an artist and not to an imprint.
 *   - **Unattended release is locked**, and not by a setting.
 */
final class ProductionPlanTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- unattended release

    #[Test]
    public function unattended_release_cannot_be_chosen(): void
    {
        // **The most important refusal in the platform.** Handing a release to
        // a distributor is the one operation SaniTube cannot undo: shops hold
        // the result, a takedown is a request rather than a delete, and a
        // delivered identifier is spent. Doing that without a person is not a
        // feature with a switch missing — it is a decision nobody has made.
        $this->assertFalse(AutonomyMode::AutonomousRelease->isAvailable());
        $this->assertNotContains(AutonomyMode::AutonomousRelease, AutonomyMode::available());

        try {
            $this->planner()->create($this->profile(), [
                'name' => 'Autumn',
                'autonomy_mode' => AutonomyMode::AutonomousRelease,
            ]);
            $this->fail('A plan must not be settable to unattended release.');
        } catch (ProductionPlanException $refused) {
            $this->assertSame('PLAN_AUTONOMY_LOCKED', $refused->reason);
        }

        $this->assertSame(0, ProductionPlan::query()->count());
    }

    #[Test]
    public function the_locked_mode_is_refused_rather_than_quietly_lowered(): void
    {
        // The worse failure of the two. An operator who asked for unattended
        // release and silently got ASSISTED believes the platform is doing
        // something it is not — and would find out at the point where a
        // release did not go out, or did.
        $plan = $this->plan(AutonomyMode::Assisted);

        $this->expectException(ProductionPlanException::class);

        $this->planner()->setAutonomy($plan, AutonomyMode::AutonomousRelease);
    }

    #[Test]
    public function no_setting_unlocks_unattended_release(): void
    {
        // A locked mode an environment variable unlocks is locked in name
        // only. Turning this on should require somebody to change code and
        // have that change read in a diff.
        config([
            'production.autonomous_release' => true,
            'production.allow_autonomous_release' => true,
            'sanitube.autonomous_release' => true,
        ]);

        $this->assertFalse(AutonomyMode::AutonomousRelease->isAvailable());

        try {
            $this->plan(AutonomyMode::AutonomousRelease);
            $this->fail('No configuration may unlock unattended release.');
        } catch (ProductionPlanException $refused) {
            $this->assertSame('PLAN_AUTONOMY_LOCKED', $refused->reason);
        }
    }

    #[Test]
    public function no_plan_that_can_exist_may_release_unattended(): void
    {
        // Asked of every mode a plan can actually hold, so the answer is not
        // "we did not build the caller yet" but "there is no state in which
        // this is true".
        foreach (AutonomyMode::available() as $mode) {
            $plan = $this->plan($mode, name: 'Plan '.$mode->value);

            $this->assertFalse(
                $plan->mayReleaseUnattended(),
                sprintf('%s must not permit unattended release.', $mode->value),
            );
        }
    }

    #[Test]
    public function a_row_holding_the_locked_mode_still_releases_nothing(): void
    {
        // **The defence that has to hold when the writer was not involved.**
        // A seeder, a migration, a raw UPDATE or a restored backup can put
        // `AUTONOMOUS_RELEASE` in the column without ever passing the service
        // that refuses it — and the answer to "may this plan deliver on its
        // own" must still be no.
        //
        // Written around the service deliberately, because going through it
        // proves only the service.
        $plan = $this->plan(AutonomyMode::AutonomousPreparation);
        $plan->forceFill(['autonomy_mode' => AutonomyMode::AutonomousRelease])->save();

        $stored = $plan->fresh();

        $this->assertInstanceOf(ProductionPlan::class, $stored);
        $this->assertSame(AutonomyMode::AutonomousRelease, $stored->autonomy_mode, 'The row really does hold it.');
        $this->assertTrue($stored->status->isRunnable());

        $this->assertFalse($stored->mayReleaseUnattended());
        $this->assertFalse($stored->autonomy_mode->mayReleaseUnattended());
    }

    // ------------------------------------------------- autonomy is the plan's

    #[Test]
    public function autonomy_is_not_a_column_on_an_artist_or_an_imprint(): void
    {
        // The same artist can appear on one plan a person drives by hand and
        // another that generates unattended, and an imprint's taste is a
        // different question from its appetite for automation. A column on
        // either would make those the same setting -- so the assertion is on
        // the schema, where a well-meaning migration would put it.
        foreach (['artists', 'editorial_profiles'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertStringNotContainsString('autonom', mb_strtolower($column), $table);
            }
        }

        $this->assertContains('autonomy_mode', Schema::getColumnListing('production_plans'));
    }

    #[Test]
    public function one_imprint_may_carry_plans_with_different_autonomy(): void
    {
        // The relationship a column on the profile would have made impossible.
        $profile = $this->profile();
        $planner = $this->planner();

        $byHand = $planner->create($profile, ['name' => 'Careful', 'autonomy_mode' => AutonomyMode::Manual]);
        $onItsOwn = $planner->create($profile, ['name' => 'Prolific', 'autonomy_mode' => AutonomyMode::AutonomousGeneration]);

        $this->assertSame(AutonomyMode::Manual, $byHand->autonomy_mode);
        $this->assertSame(AutonomyMode::AutonomousGeneration, $onItsOwn->autonomy_mode);
        $this->assertSame($profile->id, $onItsOwn->editorialProfile?->id);
    }

    #[Test]
    public function the_ladder_adds_exactly_one_permission_at_a_time(): void
    {
        // Read as "the platform may go this far by itself, and stops".
        $this->assertFalse(AutonomyMode::Manual->mayGenerateUnattended());
        $this->assertFalse(AutonomyMode::Assisted->mayGenerateUnattended());
        $this->assertTrue(AutonomyMode::AutonomousGeneration->mayGenerateUnattended());

        $this->assertFalse(AutonomyMode::AutonomousGeneration->mayPrepareReleases());
        $this->assertTrue(AutonomyMode::AutonomousPreparation->mayPrepareReleases());

        // Preparing is not delivering. A release assembled unattended still
        // sits there until a person sends it.
        $this->assertFalse(AutonomyMode::AutonomousPreparation->mayReleaseUnattended());
    }

    // ------------------------------------------------------ status and mode

    #[Test]
    public function a_paused_plan_generates_nothing_however_autonomous_it_is(): void
    {
        // **Both halves are required.** A caller that checked only the mode
        // would happily generate under a plan somebody stopped an hour ago.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);

        $this->assertTrue($plan->mayGenerateUnattended());

        $paused = $this->planner()->pause($plan);

        $this->assertSame(AutonomyMode::AutonomousGeneration, $paused->autonomy_mode);
        $this->assertFalse($paused->mayGenerateUnattended());
    }

    #[Test]
    public function only_an_active_plan_runs(): void
    {
        // Anything the platform is unsure about should stop it acting, not
        // merely be recorded beside it acting.
        $this->assertTrue(ProductionPlanStatus::Active->isRunnable());

        foreach ([
            ProductionPlanStatus::Paused,
            ProductionPlanStatus::Exhausted,
            ProductionPlanStatus::ReviewRequired,
            ProductionPlanStatus::Disabled,
        ] as $status) {
            $this->assertFalse($status->isRunnable(), $status->value);
        }
    }

    #[Test]
    public function a_plan_the_platform_stopped_is_distinguishable_from_one_a_person_stopped(): void
    {
        // The distinction that makes autonomy safe to switch on. Collapsing
        // the two would hide every self-stop inside a state that looks
        // deliberate.
        $planner = $this->planner();

        $paused = $planner->pause($this->plan(name: 'Paused'));
        $halted = $planner->requireReview($this->plan(name: 'Halted'), 'NO_PROVIDER');

        $this->assertFalse($paused->status->wasSetByThePlatform());
        $this->assertNull($paused->halted_reason);
        $this->assertNull($paused->halted_at);

        $this->assertTrue($halted->status->wasSetByThePlatform());
        $this->assertSame('NO_PROVIDER', $halted->halted_reason);
        $this->assertNotNull($halted->halted_at);
    }

    #[Test]
    public function exhausted_is_a_finished_plan_rather_than_a_stopped_one(): void
    {
        // Not a failure and not a stop somebody chose. The remedy differs: an
        // exhausted plan is raised, extended or finished; a disabled one is
        // reconsidered.
        $exhausted = $this->planner()->exhaust($this->plan());

        $this->assertSame(ProductionPlanStatus::Exhausted, $exhausted->status);
        $this->assertTrue($exhausted->status->wasSetByThePlatform());
        $this->assertFalse($exhausted->status->isResumable());
    }

    #[Test]
    public function a_stopped_plan_resumes_and_a_finished_one_does_not(): void
    {
        $planner = $this->planner();

        foreach ([ProductionPlanStatus::Paused, ProductionPlanStatus::ReviewRequired] as $status) {
            $plan = $this->plan(name: 'Resumable '.$status->value);
            $plan->forceFill(['status' => $status, 'halted_reason' => 'X', 'halted_at' => now()])->save();

            $resumed = $planner->resume($plan);

            $this->assertSame(ProductionPlanStatus::Active, $resumed->status);
            $this->assertNull($resumed->halted_reason, 'Resuming clears why it stopped.');
        }

        foreach ([ProductionPlanStatus::Exhausted, ProductionPlanStatus::Disabled] as $status) {
            $plan = $this->plan(name: 'Final '.$status->value);
            $plan->forceFill(['status' => $status])->save();

            try {
                $planner->resume($plan);
                $this->fail(sprintf('A %s plan must not simply resume.', $status->value));
            } catch (ProductionPlanException $refused) {
                $this->assertSame('PLAN_NOT_RESUMABLE', $refused->reason);
            }
        }
    }

    #[Test]
    public function a_disabled_plan_is_kept_rather_than_deleted(): void
    {
        // The work done under it still refers to it, and removing the row
        // would leave releases describing a plan that does not exist.
        $disabled = $this->planner()->disable($this->plan());

        $this->assertSame(ProductionPlanStatus::Disabled, $disabled->status);
        $this->assertSame(1, ProductionPlan::query()->count());
    }

    // ------------------------------------------------------------- writing

    #[Test]
    public function a_new_plan_is_active(): void
    {
        // A plan that arrived paused is a plan somebody has to remember to
        // start, which is how a body of work quietly never happens.
        $this->assertSame(ProductionPlanStatus::Active, $this->plan()->status);
    }

    #[Test]
    public function a_plan_defaults_to_doing_nothing_on_its_own(): void
    {
        $plan = $this->planner()->create($this->profile(), ['name' => 'Autumn']);

        $this->assertSame(AutonomyMode::Manual, $plan->autonomy_mode);
        $this->assertFalse($plan->mayGenerateUnattended());
    }

    #[Test]
    public function a_retired_imprint_takes_no_new_plans(): void
    {
        // Producing more work under an imprint somebody has stopped releasing
        // is the sort of thing only noticed at the point of delivery.
        $profile = $this->profile();
        $profile->forceFill(['is_active' => false])->save();

        try {
            $this->planner()->create($profile, ['name' => 'Autumn']);
            $this->fail('A retired imprint must take no new plans.');
        } catch (ProductionPlanException $refused) {
            $this->assertSame('PLAN_PROFILE_INACTIVE', $refused->reason);
        }
    }

    #[Test]
    public function a_target_is_recorded_and_never_enforced_by_this_table(): void
    {
        // What somebody set out to make, so "we wanted forty and got
        // thirty-one" is answerable. What actually stops a plan is its status.
        $plan = $this->planner()->create($this->profile(), [
            'name' => 'Autumn',
            'target_track_count' => 40,
            'cadence_days' => 7,
        ]);

        $this->assertSame(40, $plan->target_track_count);
        $this->assertSame(7, $plan->cadence_days);
        $this->assertTrue($plan->status->isRunnable());
    }

    #[Test]
    public function nonsense_numbers_become_nothing_rather_than_a_stored_zero(): void
    {
        $plan = $this->planner()->create($this->profile(), [
            'name' => 'Autumn',
            'target_track_count' => 0,
            'cadence_days' => -3,
        ]);

        $this->assertNull($plan->target_track_count);
        $this->assertNull($plan->cadence_days);
    }

    #[Test]
    public function two_plans_cannot_share_a_slug(): void
    {
        $planner = $this->planner();
        $profile = $this->profile();

        $planner->create($profile, ['name' => 'Autumn']);

        try {
            $planner->create($profile, ['name' => 'autumn']);
            $this->fail('Two plans with one slug is an ambiguity nothing can resolve.');
        } catch (ProductionPlanException $refused) {
            $this->assertSame('PLAN_SLUG_TAKEN', $refused->reason);
        }
    }

    #[Test]
    public function a_plan_needs_a_name(): void
    {
        $this->expectException(ProductionPlanException::class);

        $this->planner()->create($this->profile(), ['name' => '  ']);
    }

    // ------------------------------------------------------------ fixtures

    private function planner(): WriteProductionPlan
    {
        return $this->app->make(WriteProductionPlan::class);
    }

    private function profile(string $name = 'Quiet Rooms'): EditorialProfile
    {
        return $this->app->make(WriteEditorialProfile::class)->create(['name' => $name]);
    }

    private function plan(AutonomyMode $mode = AutonomyMode::Manual, string $name = 'Autumn'): ProductionPlan
    {
        return $this->planner()->create(
            $this->profile('Imprint '.bin2hex(random_bytes(4))),
            ['name' => $name, 'autonomy_mode' => $mode],
        );
    }
}
