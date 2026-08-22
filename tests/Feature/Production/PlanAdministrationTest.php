<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Services\WriteProductionPlan;
use Tests\TestCase;

/**
 * Starting the one part of SaniTube that acts without a person present.
 *
 * PROD-002. `WriteProductionPlan::create` shipped with PROD-001 and had no
 * caller anywhere in the product — no controller, no console command, no
 * seeder. Neither did `WriteEditorialProfile`, which a plan requires. So a
 * working installation had a production screen that could only ever be empty,
 * and the planner could be started only from a database client.
 *
 * The rules these tests carry:
 *
 *   - **A plan cannot exist without an imprint**, and not a retired one. A plan
 *     pointed at a retired profile produces in the manner of something the
 *     label has stopped using.
 *   - **A plan starts running.** One that arrived paused is a plan somebody has
 *     to remember to start, which is how a body of work quietly never happens.
 *   - **The status is not a field.** Pausing and resuming are named acts;
 *     `EXHAUSTED` is a conclusion the platform draws from its own counting and
 *     no form may assign it.
 *   - **The slug is frozen.** It is how a console command names a plan, and one
 *     that followed a rename would orphan every reference.
 *   - **Blank means no ceiling**, and survives the trip through a text field.
 */
final class PlanAdministrationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- creating

    #[Test]
    public function a_plan_can_be_made_from_the_screen(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->editor())
            ->post('/production/plans', [
                'name' => 'Ambient Wednesdays',
                'editorial_profile' => $profile->uuid,
                'autonomy_mode' => 'ASSISTED',
                'cadence_days' => 7,
                'target_track_count' => 52,
                'notes' => 'One a week until the year is full.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan = ProductionPlan::query()->firstOrFail();

        $this->assertSame('Ambient Wednesdays', $plan->name);
        $this->assertSame('ambient-wednesdays', $plan->slug);
        $this->assertSame($profile->id, $plan->editorial_profile_id);
        $this->assertSame(AutonomyMode::Assisted, $plan->autonomy_mode);
        $this->assertSame(7, $plan->cadence_days);
        $this->assertSame(52, $plan->target_track_count);
    }

    #[Test]
    public function a_plan_starts_running(): void
    {
        // The surprising default, and the right one: a plan that arrived
        // paused is a plan somebody has to remember to start.
        $this->actingAs($this->editor())
            ->post('/production/plans', ['name' => 'Open Ended', 'editorial_profile' => $this->profile()->uuid]);

        $this->assertSame(ProductionPlanStatus::Active, ProductionPlan::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_plan_with_no_ceiling_is_a_legitimate_plan(): void
    {
        // Blank means "runs until somebody stops it", which is the shape of an
        // open-ended imprint. A required cadence would make that unsayable.
        $this->actingAs($this->editor())
            ->post('/production/plans', [
                'name' => 'Open Ended',
                'editorial_profile' => $this->profile()->uuid,
                'cadence_days' => null,
                'target_track_count' => null,
            ])
            ->assertSessionHasNoErrors();

        $plan = ProductionPlan::query()->firstOrFail();

        $this->assertNull($plan->cadence_days);
        $this->assertNull($plan->target_track_count);
    }

    #[Test]
    public function a_plan_cannot_be_pointed_at_a_retired_imprint(): void
    {
        $retired = $this->profile('Retired Imprint');
        $retired->forceFill(['is_active' => false])->save();

        $this->actingAs($this->editor())
            ->post('/production/plans', ['name' => 'Doomed', 'editorial_profile' => $retired->uuid])
            ->assertSessionHasErrors(['production' => 'PLAN_PROFILE_INACTIVE']);

        $this->assertSame(0, ProductionPlan::query()->count());
    }

    #[Test]
    public function a_plan_needs_an_imprint_that_exists(): void
    {
        $this->actingAs($this->editor())
            ->post('/production/plans', ['name' => 'Doomed', 'editorial_profile' => 'not-a-uuid'])
            ->assertSessionHasErrors(['production' => 'PLAN_PROFILE_UNKNOWN']);

        $this->assertSame(0, ProductionPlan::query()->count());
    }

    #[Test]
    public function two_plans_cannot_share_a_short_name(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->editor())
            ->post('/production/plans', ['name' => 'Ambient', 'editorial_profile' => $profile->uuid]);

        $this->actingAs($this->editor())
            ->post('/production/plans', ['name' => 'Ambient', 'editorial_profile' => $profile->uuid])
            ->assertSessionHasErrors(['production' => 'PLAN_SLUG_TAKEN']);

        $this->assertSame(1, ProductionPlan::query()->count());
    }

    #[Test]
    public function the_mode_that_is_locked_is_refused_and_never_quietly_lowered(): void
    {
        // An operator who asked for unattended release and silently got
        // ASSISTED believes the platform is doing something it is not.
        $this->actingAs($this->editor())
            ->post('/production/plans', [
                'name' => 'Too Much',
                'editorial_profile' => $this->profile()->uuid,
                'autonomy_mode' => 'AUTONOMOUS_RELEASE',
            ])
            ->assertSessionHasErrors('production');

        $this->assertSame(0, ProductionPlan::query()->count());
    }

    #[Test]
    public function a_plan_needs_a_name_and_an_imprint_named_in_the_request(): void
    {
        // Distinct from naming one that does not exist: an absent field is a
        // form that was submitted incomplete, and it belongs to the field
        // rather than to the general refusal at the top of the screen.
        $this->actingAs($this->editor())
            ->post('/production/plans', [])
            ->assertSessionHasErrors(['name', 'editorial_profile']);

        $this->assertSame(0, ProductionPlan::query()->count());
    }

    // ------------------------------------------------------------- changing

    #[Test]
    public function the_terms_of_a_plan_can_be_corrected(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, [
                'name' => 'Ambient Thursdays',
                'cadence_days' => 14,
                'target_track_count' => 26,
                'notes' => 'Slower.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame('Ambient Thursdays', $plan->name);
        $this->assertSame(14, $plan->cadence_days);
        $this->assertSame(26, $plan->target_track_count);
    }

    #[Test]
    public function renaming_a_plan_leaves_its_short_name_alone(): void
    {
        // The slug is how a console command names a plan. One that followed a
        // rename would turn "rename this plan" into "orphan everything that
        // referred to it".
        $plan = $this->plan();
        $slug = $plan->slug;

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, ['name' => 'Something Else Entirely']);

        $this->assertSame($slug, $plan->refresh()->slug);
    }

    #[Test]
    public function a_plan_can_be_repointed_at_another_imprint(): void
    {
        $plan = $this->plan();
        $other = $this->profile('Second Imprint');

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, ['editorial_profile' => $other->uuid])
            ->assertSessionHasNoErrors();

        $this->assertSame($other->id, $plan->refresh()->editorial_profile_id);
    }

    #[Test]
    public function a_plan_cannot_be_repointed_at_a_retired_imprint(): void
    {
        $plan = $this->plan();
        $retired = $this->profile('Retired Imprint');
        $retired->forceFill(['is_active' => false])->save();
        $was = $plan->editorial_profile_id;

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, ['editorial_profile' => $retired->uuid])
            ->assertSessionHasErrors(['production' => 'PLAN_PROFILE_INACTIVE']);

        $this->assertSame($was, $plan->refresh()->editorial_profile_id);
    }

    #[Test]
    public function no_form_can_assign_a_status(): void
    {
        // `EXHAUSTED` is a conclusion the platform draws from its own
        // counting, and `ACTIVE` by assignment would present resuming as
        // something a form does rather than a decision somebody makes.
        $plan = $this->plan();

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, [
                'status' => 'EXHAUSTED',
                'halted_reason' => 'TARGET_REACHED',
                'name' => 'Still Fine',
            ])
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame(ProductionPlanStatus::Active, $plan->status);
        $this->assertNull($plan->halted_reason);
        $this->assertSame('Still Fine', $plan->name);
    }

    #[Test]
    public function a_plan_that_is_only_renamed_keeps_the_terms_it_had(): void
    {
        // "Not mentioned" and "cleared" are different intentions, and the
        // first must not perform the second. A plan whose cadence vanished
        // because somebody fixed a typo in its name opens no more occasions.
        $plan = $this->plan();

        $this->actingAs($this->editor())
            ->patch('/production/plans/'.$plan->uuid, ['name' => 'Renamed Only'])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $plan->refresh()->cadence_days);
    }

    #[Test]
    public function the_writer_itself_ignores_anything_outside_its_own_list(): void
    {
        // The screen test above cannot reach this. The request passes on a
        // closed list of fields and the writer reads a closed list of them,
        // and each is the other's backstop — so a test that goes through both
        // passes whichever one is removed. This one goes through the writer
        // directly, which is the layer that does the writing.
        $plan = $this->plan();

        $this->app->make(WriteProductionPlan::class)->update($plan, [
            'status' => ProductionPlanStatus::Exhausted->value,
            'halted_reason' => 'TARGET_REACHED',
            'slug' => 'a-different-short-name',
            'name' => 'Renamed',
        ]);

        $plan->refresh();

        $this->assertSame(ProductionPlanStatus::Active, $plan->status);
        $this->assertNull($plan->halted_reason);
        $this->assertSame('ambient-wednesdays', $plan->slug);
        $this->assertSame('Renamed', $plan->name);
    }

    // ---------------------------------------------------------- the imprint

    #[Test]
    public function an_imprint_can_be_made_and_a_plan_pointed_at_it(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Usual Credit']);

        $this->actingAs($this->editor())
            ->post('/editorial/profiles', [
                'name' => 'Night Records',
                'summary' => 'Slow, wordless, late.',
                'default_language' => 'fr',
                'default_artist' => $artist->uuid,
                'preferred_genres' => ['Ambient', 'ambient', 'Drone'],
                'avoided_terms' => ['remix'],
                'title_guidance' => 'Two words, lowercase.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $profile = EditorialProfile::query()->firstOrFail();

        $this->assertSame('night-records', $profile->slug);
        $this->assertTrue($profile->is_active);
        $this->assertSame('fr', $profile->default_language);
        $this->assertSame($artist->id, $profile->default_artist_id);

        // Deduplicated case-insensitively. A palette holding "Ambient" and
        // "ambient" makes a prompt repeat itself.
        $this->assertSame(['Ambient', 'Drone'], $profile->preferred_genres);
    }

    #[Test]
    public function an_imprint_is_retired_rather_than_deleted(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($profile->refresh()->is_active);
        $this->assertSame(1, EditorialProfile::query()->count());
    }

    #[Test]
    public function a_retired_imprint_is_not_offered_to_a_plan(): void
    {
        $active = $this->profile('Still Going');
        $this->profile('Gone')->forceFill(['is_active' => false])->save();

        $offered = $this->actingAs($this->editor())
            ->get('/production')
            ->viewData('page')['props']['profiles'];

        // A choice that fails on save is worse than a choice not offered.
        $this->assertSame([['uuid' => $active->uuid, 'name' => $active->name]], $offered);
    }

    #[Test]
    public function renaming_an_imprint_leaves_its_short_name_alone(): void
    {
        $profile = $this->profile();
        $slug = $profile->slug;

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['name' => 'Renamed Entirely']);

        $profile->refresh();

        $this->assertSame('Renamed Entirely', $profile->name);
        $this->assertSame($slug, $profile->slug);
    }

    #[Test]
    public function a_language_is_a_code_and_never_a_sentence(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['default_language' => 'French'])
            ->assertSessionHasErrors('default_language');

        $this->assertNull($profile->refresh()->default_language);
    }

    #[Test]
    public function an_artist_that_does_not_exist_is_refused(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['default_artist' => 'not-a-uuid'])
            ->assertSessionHasErrors(['editorial' => 'EDITORIAL_ARTIST_UNKNOWN']);

        $this->assertNull($profile->refresh()->default_artist_id);
    }

    #[Test]
    public function an_imprint_that_is_only_renamed_keeps_its_usual_credit(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Usual Credit']);
        $profile = $this->profile();
        $profile->forceFill(['default_artist_id' => $artist->id])->save();

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['name' => 'Renamed Only'])
            ->assertSessionHasNoErrors();

        // The field was not mentioned, so it was not cleared. An empty one is
        // how somebody says "no usual credit", and reopening a form is not.
        $this->assertSame($artist->id, $profile->refresh()->default_artist_id);
    }

    #[Test]
    public function an_imprint_can_be_told_it_has_no_usual_credit(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Former Credit']);
        $profile = $this->profile();
        $profile->forceFill(['default_artist_id' => $artist->id])->save();

        $this->actingAs($this->editor())
            ->patch('/editorial/profiles/'.$profile->uuid, ['default_artist' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($profile->refresh()->default_artist_id);
    }

    // ------------------------------------------------------------- the log

    #[Test]
    public function every_change_names_the_person_and_what_they_changed(): void
    {
        $editor = $this->editor();
        $profile = $this->profile();

        $this->actingAs($editor)
            ->post('/production/plans', [
                'name' => 'Audited',
                'editorial_profile' => $profile->uuid,
                'cadence_days' => 7,
            ]);

        $created = AuditEvent::query()
            ->where('action', AuditAction::ProductionPlanCreated->value)
            ->firstOrFail();

        $this->assertSame($editor->id, $created->actor_id);
        $this->assertSame(ProductionPlan::query()->firstOrFail()->uuid, $created->subject_uuid);

        // The terms travel with it. "Who made a plan" without "how often, and
        // how many" is the half of the answer that does not explain a month of
        // generations — and a plan's own row holds only what it is now.
        $this->assertSame('7', $created->context['cadence_days'] ?? null);
        $this->assertSame('NO_TARGET', $created->context['target_track_count'] ?? null);

        $plan = ProductionPlan::query()->firstOrFail();

        $this->actingAs($editor)->patch('/production/plans/'.$plan->uuid, ['cadence_days' => 30]);

        $updated = AuditEvent::query()
            ->where('action', AuditAction::ProductionPlanUpdated->value)
            ->firstOrFail();

        // Both sides of the change. Raising a cadence is what makes a plan
        // spend more, and the size of the change is the fact somebody is
        // looking for a month later.
        $this->assertSame('30', $updated->context['cadence_days'] ?? null);
        $this->assertSame('7', $updated->context['was_cadence_days'] ?? null);
    }

    #[Test]
    public function the_log_records_the_terms_and_never_the_guidance(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post('/editorial/profiles', [
            'name' => 'Night Records',
            // The label's own writing. An audit log is not where a copy of it
            // accumulates, one revision per edit, for ever.
            'title_guidance' => 'A phrase nobody should find in a log.',
        ]);

        $profile = EditorialProfile::query()->firstOrFail();

        $this->actingAs($editor)->patch('/editorial/profiles/'.$profile->uuid, [
            'title_guidance' => 'A second phrase nobody should find in a log.',
        ]);

        $events = (string) AuditEvent::query()
            ->whereIn('action', [
                AuditAction::EditorialProfileCreated->value,
                AuditAction::EditorialProfileUpdated->value,
            ])
            ->get()
            ->toJson();

        $this->assertStringNotContainsString('nobody should find in a log', $events);
        $this->assertStringContainsString('title_guidance', $events);
    }

    // ------------------------------------------------------- who may do this

    #[Test]
    public function a_member_may_watch_a_plan_and_never_steer_one(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member, 'is_active' => true]);
        $plan = $this->plan();

        $this->actingAs($member)->get('/production')->assertOk();

        $this->actingAs($member)
            ->post('/production/plans', ['name' => 'Nope', 'editorial_profile' => $this->profile('Other')->uuid])
            ->assertForbidden();
        $this->actingAs($member)->patch('/production/plans/'.$plan->uuid, ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($member)->get('/editorial')->assertForbidden();
        $this->actingAs($member)->post('/editorial/profiles', ['name' => 'Nope'])->assertForbidden();

        $this->assertSame(1, ProductionPlan::query()->count());
    }

    #[Test]
    public function a_stranger_reaches_none_of_it(): void
    {
        $this->get('/editorial')->assertRedirect('/login');
        $this->post('/production/plans', ['name' => 'Nope'])->assertRedirect('/login');
    }

    // ---------------------------------------------------------- the fixtures

    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function profile(string $name = 'Night Records'): EditorialProfile
    {
        return EditorialProfile::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'is_active' => true,
        ]);
    }

    private function plan(): ProductionPlan
    {
        return ProductionPlan::query()->create([
            'name' => 'Ambient Wednesdays',
            'slug' => 'ambient-wednesdays',
            'editorial_profile_id' => $this->profile('Plan Imprint')->id,
            'autonomy_mode' => AutonomyMode::Manual,
            'status' => ProductionPlanStatus::Active,
            'cadence_days' => 7,
        ]);
    }
}
