<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\Services\OpenProductionSlots;
use SaniTube\Production\Services\WriteProductionPlan;
use Tests\TestCase;

/**
 * PROD-UI-001 — watching, and stopping, the part that acts alone.
 *
 * PROD-001 through PROD-004 built the one thing in SaniTube that runs with
 * nobody present: it decides that more music should exist and pays a supplier
 * for it. Until this ticket **the only way to see or stop any of that was a
 * shell** — `artisan` and a database client — which meant the most consequential
 * subsystem in the product was the one an operator could not watch.
 *
 * Three claims:
 *
 *   1. **Stopping always works, and does not need a shell.** A pause is allowed
 *      from every unsettled state, including one the platform set itself.
 *   2. **No supplier credential reaches the browser.** An occasion carries a
 *      generation, a generation carries a `provider_job_id`, and on a
 *      query-string-authenticated provider that string is a credential.
 *   3. **A skip is not a failure**, and the screen keeps them apart — separate
 *      counts, separate wording. A list that merged them would teach an
 *      operator to ignore the failure count.
 */
final class ProductionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ------------------------------------------------------------- who may look

    #[Test]
    public function every_production_screen_is_behind_authentication(): void
    {
        $plan = $this->plan();

        $this->get('/production')->assertRedirect(route('login'));
        $this->get('/production/plans/'.$plan->uuid)->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_may_watch_what_the_platform_did_and_change_none_of_it(): void
    {
        // Watching an autonomous planner is not a privilege; being unable to
        // watch it is how an operator discovers a month of generations from a
        // supplier's bill.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);
        $slot = $this->occasion($plan);
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/production')->assertOk();

        $this->actingAs($member)
            ->get('/production/plans/'.$plan->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('may_steer', false));

        $this->actingAs($member)->post('/production/plans/'.$plan->uuid.'/pause')->assertForbidden();
        $this->actingAs($member)->post('/production/plans/'.$plan->uuid.'/resume')->assertForbidden();
        $this->actingAs($member)
            ->post('/production/plans/'.$plan->uuid.'/autonomy', ['autonomy_mode' => 'MANUAL'])
            ->assertForbidden();
        $this->actingAs($member)->post('/production/occasions/'.$slot->uuid.'/cancel')->assertForbidden();

        // Nothing moved.
        $this->assertSame(AutonomyMode::AutonomousGeneration, $plan->refresh()->autonomy_mode);
        $this->assertSame(ProductionSlotStatus::Pending, $slot->refresh()->status);
    }

    #[Test]
    public function screens_are_addressed_by_uuid_and_never_by_internal_id(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->owner())->get('/production/plans/'.$plan->id)->assertNotFound();
        $this->actingAs($this->owner())->get('/production/plans/'.$plan->uuid)->assertOk();
    }

    // --------------------------------------------------- no credential leaves

    #[Test]
    public function no_provider_job_id_or_prompt_reaches_the_browser(): void
    {
        // A provider job id on a query-string-authenticated provider *is* a
        // bearer credential, and a prompt is the operator's own work.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);
        $slot = $this->occasion($plan);

        $generation = MusicGeneration::query()->create([
            'provider' => 'fake',
            'provider_job_id' => 'job-with-a-secret-in-it',
            'status' => MusicGenerationStatus::Completed,
            'prompt' => 'a private prompt somebody wrote',
            'lyrics' => 'private words',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);

        $slot->forceFill([
            'status' => ProductionSlotStatus::Claimed,
            'music_generation_id' => $generation->getKey(),
            'attempted_providers' => ['fake'],
        ])->save();

        $response = $this->actingAs($this->owner())->get('/production/plans/'.$plan->uuid);

        $response->assertOk();

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('job-with-a-secret-in-it', $body);
        $this->assertStringNotContainsString('a private prompt somebody wrote', $body);
        $this->assertStringNotContainsString('private words', $body);

        // What does travel: enough to open the studio screen that owns it.
        $response->assertInertia(fn ($page) => $page
            ->where('occasions.0.generation.uuid', $generation->uuid)
            ->where('occasions.0.generation.status', 'COMPLETED')
            ->where('occasions.0.attempted_providers', ['fake']));
    }

    // ------------------------------------------------- a skip is not a failure

    #[Test]
    public function the_list_counts_every_outcome_including_the_ones_at_zero(): void
    {
        // A plan that has skipped forty occasions and failed none looks
        // identical to one that has done nothing if absent keys render as
        // absent.
        $plan = $this->plan();
        $this->occasion($plan, ProductionSlotStatus::Skipped, 'TARGET_REACHED');

        $this->actingAs($this->owner())
            ->get('/production')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('page.rows.0.occasions.SKIPPED', 1)
                ->where('page.rows.0.occasions.FAILED', 0)
                ->where('page.rows.0.occasions.PENDING', 0)
                ->where('page.rows.0.occasions.CLAIMED', 0)
                ->where('page.rows.0.occasions.COMPLETED', 0)
                ->where('page.rows.0.occasions.CANCELLED', 0)
                ->where('page.rows.0.occasions.total', 1));
    }

    #[Test]
    public function a_skipped_occasion_says_so_apart_from_a_failed_one(): void
    {
        $plan = $this->plan();
        $this->occasion($plan, ProductionSlotStatus::Skipped, 'ENOUGH_IN_FLIGHT');
        $this->occasion($plan, ProductionSlotStatus::Failed, 'PROVIDER_UNAVAILABLE', daysAhead: 7);

        $this->actingAs($this->owner())
            ->get('/production/plans/'.$plan->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Newest first: the failure was scheduled a week later.
                ->where('occasions.0.status', 'FAILED')
                ->where('occasions.0.was_skipped', false)
                ->where('occasions.1.status', 'SKIPPED')
                ->where('occasions.1.was_skipped', true)
                ->where('occasions.1.outcome_reason', 'ENOUGH_IN_FLIGHT'));
    }

    #[Test]
    public function autonomy_and_running_are_two_different_questions(): void
    {
        // A plan can be granted the right to act alone and be paused. An
        // operator looking at a quiet month needs to know which it is.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);
        $this->app->make(WriteProductionPlan::class)->pause($plan);

        $this->actingAs($this->owner())
            ->get('/production')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('page.rows.0.autonomy_mode', 'AUTONOMOUS_GENERATION')
                ->where('page.rows.0.status', 'PAUSED')
                // Granted, and not running. Both true at once.
                ->where('page.rows.0.may_generate_unattended', false)
                ->where('page.rows.0.is_runnable', false)
                ->where('page.rows.0.is_resumable', true));
    }

    #[Test]
    public function a_running_plan_nobody_granted_autonomy_to_may_still_do_nothing_alone(): void
    {
        // The other half of the same distinction, and the common case: most
        // plans run and are not allowed to act alone. Without this, reporting
        // "running" as "may act alone" would look right on every paused plan.
        $this->plan(AutonomyMode::Manual);

        $this->actingAs($this->owner())
            ->get('/production')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('page.rows.0.status', 'ACTIVE')
                ->where('page.rows.0.is_runnable', true)
                // Running, and not permitted. The two disagree, which is the
                // whole reason they are separate fields.
                ->where('page.rows.0.may_generate_unattended', false));
    }

    // -------------------------------------------------------------- stopping

    #[Test]
    public function stopping_a_plan_works_from_the_screen_and_is_audited(): void
    {
        // The claim that carries this ticket. Until now the only stop was a
        // shell.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/pause')
            ->assertRedirect();

        $this->assertSame(ProductionPlanStatus::Paused, $plan->refresh()->status);
        $this->assertFalse($plan->mayGenerateUnattended());

        $this->assertAudited(AuditAction::ProductionPlanPaused, $plan->uuid);
    }

    #[Test]
    public function a_plan_the_platform_stopped_itself_can_still_be_paused(): void
    {
        // A pause button that refused because the plan was already stopped
        // would make somebody work out which kind of stopped it was before
        // they could be sure it would not start again.
        $plan = $this->plan(AutonomyMode::AutonomousGeneration);
        $this->app->make(WriteProductionPlan::class)->requireReview($plan, 'SOMETHING_ODD');

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/pause')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame(ProductionPlanStatus::Paused, $plan->status);
        // The platform's reason is cleared, because a person's stop is not the
        // platform's stop and keeping the old reason would misattribute it.
        $this->assertNull($plan->halted_reason);
    }

    #[Test]
    public function resuming_an_exhausted_plan_is_refused_with_a_reason(): void
    {
        // An exhausted plan needs its target raised; that is not something a
        // resume button should do silently on somebody's behalf.
        $plan = $this->plan();
        $this->app->make(WriteProductionPlan::class)->exhaust($plan);

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/resume')
            ->assertSessionHasErrors(['production' => 'PLAN_NOT_RESUMABLE']);

        $this->assertSame(ProductionPlanStatus::Exhausted, $plan->refresh()->status);
        $this->assertRefused(AuditAction::ProductionPlanResumed, 'PLAN_NOT_RESUMABLE');
    }

    #[Test]
    public function a_paused_plan_resumes_from_the_screen(): void
    {
        $plan = $this->plan();
        $this->app->make(WriteProductionPlan::class)->pause($plan);

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/resume')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ProductionPlanStatus::Active, $plan->refresh()->status);
        $this->assertAudited(AuditAction::ProductionPlanResumed, $plan->uuid);
    }

    // ------------------------------------------------------------- autonomy

    #[Test]
    public function granting_autonomy_records_what_it_was_granted_to(): void
    {
        // "Who changed the autonomy" without "to what" is half an answer.
        $plan = $this->plan();

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/autonomy', [
                'autonomy_mode' => AutonomyMode::AutonomousGeneration->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(AutonomyMode::AutonomousGeneration, $plan->refresh()->autonomy_mode);

        $event = AuditEvent::query()
            ->where('action', AuditAction::ProductionAutonomyChanged->value)
            ->firstOrFail();

        $this->assertSame('AUTONOMOUS_GENERATION', $event->context['autonomy_mode'] ?? null);
    }

    #[Test]
    public function the_locked_mode_is_refused_with_the_true_reason(): void
    {
        // Offered to validation on purpose: refusing it as "not a mode" would
        // be false, and "that mode is not available yet" is the truth.
        $plan = $this->plan();

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/autonomy', [
                'autonomy_mode' => AutonomyMode::AutonomousRelease->value,
            ])
            ->assertSessionHasErrors(['production' => 'PLAN_AUTONOMY_LOCKED']);

        $this->assertSame(AutonomyMode::Manual, $plan->refresh()->autonomy_mode);
    }

    #[Test]
    public function a_mode_that_is_not_a_mode_is_a_validation_error_and_not_an_exception(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->owner())
            ->post('/production/plans/'.$plan->uuid.'/autonomy', ['autonomy_mode' => 'DO_WHATEVER'])
            ->assertSessionHasErrors(['autonomy_mode']);
    }

    // ------------------------------------------------------------- occasions

    #[Test]
    public function a_person_can_call_off_an_occasion_a_worker_has_already_taken(): void
    {
        // ClaimProductionSlot's own rule: a cancel that only worked before
        // anything picked it up would be one that never works when it matters.
        $plan = $this->plan();
        $slot = $this->occasion($plan, ProductionSlotStatus::Claimed);

        $this->actingAs($this->owner())
            ->get('/production/plans/'.$plan->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('occasions.0.is_cancellable', true));

        $this->actingAs($this->owner())
            ->post('/production/occasions/'.$slot->uuid.'/cancel')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Cancelled, $slot->status);
        $this->assertTrue($slot->status->wasSetByAPerson());
        $this->assertAudited(AuditAction::ProductionOccasionCancelled, $slot->uuid);
    }

    #[Test]
    public function a_settled_occasion_is_not_re_settled_by_a_second_click(): void
    {
        $plan = $this->plan();
        $slot = $this->occasion($plan, ProductionSlotStatus::Completed, 'GENERATION_COMPLETED');

        // The screen does not offer the button in the first place. A button
        // that is always refused teaches somebody that refusals are normal --
        // and this is presentation, so the route check below is the guard.
        $this->actingAs($this->owner())
            ->get('/production/plans/'.$plan->uuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('occasions.0.is_cancellable', false));

        $this->actingAs($this->owner())
            ->post('/production/occasions/'.$slot->uuid.'/cancel')
            ->assertSessionHasErrors(['production' => 'ALREADY_SETTLED']);

        // Re-settling would rewrite how it actually ended.
        $this->assertSame(ProductionSlotStatus::Completed, $slot->refresh()->status);
    }

    // ------------------------------------------------------------- the shape

    #[Test]
    public function a_cursor_from_another_list_is_refused_rather_than_showing_an_unfiltered_page(): void
    {
        $this->plan();

        $this->actingAs($this->owner())
            ->get('/production?cursor='.urlencode(base64_encode(json_encode(['id' => 1, '_pointsToNextItems' => true]) ?: '')))
            ->assertStatus(422);
    }

    #[Test]
    public function production_is_reachable_from_the_navigation(): void
    {
        // A screen nothing links to is a screen nobody finds.
        $this->actingAs($this->owner())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'navigation',
                function (mixed $items): bool {
                    // The fluent assertion hands over a Collection, and its
                    // members are whatever the navigation tree put there.
                    // Walked by hand rather than with collect(), which cannot
                    // infer its own types from `mixed`.
                    foreach (($items instanceof Collection ? $items->all() : (array) $items) as $item) {
                        if (is_array($item) && ($item['href'] ?? null) === '/production') {
                            return true;
                        }
                    }

                    return false;
                },
            ));
    }

    #[Test]
    public function every_state_and_reason_the_screen_can_render_has_a_name_in_every_language(): void
    {
        // A state added in PHP and not translated renders as its own key, which
        // is how an interface starts showing SHOUTING_SNAKE_CASE to an operator.
        $reasons = [
            'NO_ATTRIBUTION', 'NO_TARGET', 'TARGET_REACHED', 'ENOUGH_IN_FLIGHT',
            'PLAN_NOT_RUNNABLE', 'AUTONOMY_NOT_GRANTED', 'CEILING_REACHED',
            'PROVIDER_CIRCUIT_OPEN', 'PROVIDER_UNAVAILABLE', 'PROVIDER_INCAPABLE',
            'NO_CAPABLE_PROVIDER', 'GENERATION_COMPLETED', 'GENERATION_CANCELLED',
            'GENERATION_FAILED',
        ];

        foreach (['en', 'fr', 'es', 'de', 'it', 'pt'] as $locale) {
            foreach (ProductionPlanStatus::cases() as $status) {
                $this->assertTranslated('ui.status.production_plan.'.$status->value, $locale);
            }

            foreach (ProductionSlotStatus::cases() as $status) {
                $this->assertTranslated('ui.status.occasion.'.$status->value, $locale);
            }

            foreach (AutonomyMode::cases() as $mode) {
                $this->assertTranslated('ui.production.autonomy_mode.'.$mode->value, $locale);
            }

            foreach ($reasons as $reason) {
                $this->assertTranslated('ui.production.reason.'.$reason, $locale);
            }

            foreach (['PLAN_NOT_RESUMABLE', 'PLAN_AUTONOMY_LOCKED', 'ALREADY_SETTLED'] as $failure) {
                $this->assertTranslated('ui.production.failure.'.$failure, $locale);
            }
        }
    }

    // --------------------------------------------------------------- helpers

    private function assertTranslated(string $key, string $locale): void
    {
        $this->assertNotSame($key, trans($key, [], $locale), sprintf('[%s] has no name in [%s].', $key, $locale));
    }

    private function assertAudited(AuditAction $action, string $subjectUuid): void
    {
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', $action->value)
                ->where('subject_uuid', $subjectUuid)
                ->where('outcome', AuditOutcome::Succeeded->value)
                ->exists(),
            sprintf('[%s] was not audited.', $action->value),
        );
    }

    private function assertRefused(AuditAction $action, string $reason): void
    {
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', $action->value)
                ->where('outcome', AuditOutcome::Refused->value)
                ->where('reason', $reason)
                ->exists(),
            sprintf('[%s] refusal was not audited.', $action->value),
        );
    }

    private function plan(AutonomyMode $mode = AutonomyMode::Manual): ProductionPlan
    {
        $profile = $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
            'default_artist_id' => Artist::factory()->create()->id,
        ]);

        return $this->app->make(WriteProductionPlan::class)->create($profile, [
            'name' => 'Autumn '.bin2hex(random_bytes(3)),
            'autonomy_mode' => $mode,
            'cadence_days' => 7,
            'target_track_count' => 10,
        ]);
    }

    private function occasion(
        ProductionPlan $plan,
        ProductionSlotStatus $status = ProductionSlotStatus::Pending,
        ?string $reason = null,
        int $daysAhead = 0,
    ): ProductionSlot {
        // The unique index is (plan, date), so the date is what makes a second
        // occasion for the same plan a different one. The scheduler opens
        // today's; anything else is written directly.
        if ($daysAhead === 0) {
            $opened = $this->app->make(OpenProductionSlots::class)->handle();
            $slot = end($opened);
        } else {
            $slot = false;
        }

        if (! $slot instanceof ProductionSlot) {
            $slot = ProductionSlot::query()->create([
                'production_plan_id' => $plan->id,
                'scheduled_for' => now()->addDays($daysAhead),
                'status' => ProductionSlotStatus::Pending,
                'intent' => ProductionSlot::INTENT_PRODUCE_TRACK,
            ]);
        }

        $slot->forceFill([
            'status' => $status,
            'outcome_reason' => $reason,
            'settled_at' => $status->isSettled() ? now() : null,
        ])->save();

        return $slot->refresh();
    }

    private function owner(): User
    {
        return $this->user(UserRole::Owner, 'owner@example.test');
    }

    private function user(UserRole $role, string $email): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $existing;
        }

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }
}
