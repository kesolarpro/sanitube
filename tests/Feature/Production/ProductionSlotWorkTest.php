<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Models\Track;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
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
use SaniTube\Production\Services\SettleProductionSlot;
use SaniTube\Production\Services\WriteProductionPlan;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * PROD-004 — the occasion becomes a request, and comes back.
 *
 * PROD-003 stopped one step short on purpose: `WorkProductionSlot` decides that
 * generating is warranted, leaves the slot CLAIMED and says so in its docblock.
 * **Nothing was the next thing.** The whole production engine could decide and
 * never act, and no test in the repository could have told a working planner
 * apart from one that did nothing at all.
 *
 * Three claims carry this ticket, and two of them are negative:
 *
 *   1. **Starting is not producing.** A request queued at a supplier leaves the
 *      occasion CLAIMED, never COMPLETED, and creates no Track. The audio takes
 *      minutes, arrives asynchronously and can fail.
 *   2. **A supplier failing does not lose the occasion.** A plan that wanted a
 *      track on the 14th still wants one; the occasion is re-offered to the
 *      next supplier that could serve it, and never back to the one that just
 *      failed.
 *   3. **A refusal that resolves itself is a skip, not a failure.** A ceiling
 *      is rolling and a circuit closes on its own. Filing those under failures
 *      teaches an operator to ignore the failure list.
 */
final class ProductionSlotWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        $this->useProviders('primary');

        Queue::fake();
    }

    // ------------------------------------------------ starting is not producing

    #[Test]
    public function a_warranted_occasion_becomes_a_queued_request_and_stays_in_flight(): void
    {
        $slot = $this->dueSlot();

        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $this->assertSame('primary', $generation->provider);
        $this->assertSame(MusicGenerationStatus::Queued, $generation->status);

        // Queued, not called. The audio takes minutes and a command must not.
        Queue::assertPushed(SubmitMusicGenerationJob::class);

        $slot->refresh();

        // CLAIMED, not COMPLETED. Completing here would record that something
        // was made before anything was.
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertSame($generation->getKey(), $slot->music_generation_id);
        $this->assertNull($slot->settled_at);

        // And nothing entered the catalogue.
        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function the_occasion_records_which_supplier_it_was_offered_to(): void
    {
        // The answer to "why does this track exist", read from the other end.
        $slot = $this->dueSlot();

        $this->producer()->handle($slot, 'test-worker');

        $this->assertSame(['primary'], $slot->refresh()->attempted());
    }

    #[Test]
    public function an_unwarranted_occasion_spends_nothing(): void
    {
        // PROD-003's inventory comes first and this proves the wiring kept it
        // there: a plan nobody granted autonomy to is skipped, not generated.
        $slot = $this->dueSlot(AutonomyMode::Assisted);

        $this->assertNull($this->producer()->handle($slot, 'test-worker'));

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Skipped, $slot->status);
        $this->assertSame('AUTONOMY_NOT_GRANTED', $slot->outcome_reason);
        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function what_is_asked_for_comes_from_the_plans_own_voice(): void
    {
        // A prompt written in the producer would be a second place an
        // installation's voice is decided, silently different from the one an
        // operator can see and edit.
        $profile = $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Nocturnes',
            'summary' => 'Slow piano for late listening.',
            'preferred_genres' => ['ambient'],
            'default_artist_id' => Artist::factory()->create()->id,
        ]);

        $slot = $this->dueSlot(profile: $profile);

        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $this->assertStringContainsString('Slow piano for late listening.', $generation->prompt);
        $this->assertStringContainsString('ambient', $generation->prompt);
    }

    // ------------------------------------------------ a supplier failing loses nothing

    #[Test]
    public function a_failed_generation_is_offered_to_another_supplier(): void
    {
        $this->useProviders('primary', 'secondary');

        $slot = $this->dueSlot();
        $first = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $first);
        $first->forceFill(['status' => MusicGenerationStatus::Failed])->save();

        $settled = $this->settler()->handle($slot->refresh());

        $this->assertInstanceOf(ProductionSlot::class, $settled);

        // Still in flight, with somebody else. A plan that wanted a track on
        // the 14th still wants one.
        $this->assertSame(ProductionSlotStatus::Claimed, $settled->status);
        $this->assertSame(['primary', 'secondary'], $settled->attempted());

        $second = $settled->generation;

        $this->assertInstanceOf(MusicGeneration::class, $second);
        $this->assertSame('secondary', $second->provider);
        $this->assertNotSame($first->getKey(), $second->getKey());
    }

    #[Test]
    public function the_occasion_is_never_offered_back_to_the_supplier_that_failed(): void
    {
        $this->useProviders('primary', 'secondary');

        $slot = $this->dueSlot();
        $first = $this->producer()->handle($slot, 'test-worker');
        $this->assertInstanceOf(MusicGeneration::class, $first);

        $first->forceFill(['status' => MusicGenerationStatus::Failed])->save();
        $this->settler()->handle($slot->refresh());

        // The second one fails too. There is nobody left who has not been
        // tried, so the occasion ends rather than cycling between two
        // suppliers for ever.
        $second = $slot->refresh()->generation;
        $this->assertInstanceOf(MusicGeneration::class, $second);
        $second->forceFill(['status' => MusicGenerationStatus::Failed])->save();

        $settled = $this->settler()->handle($slot->refresh());

        $this->assertInstanceOf(ProductionSlot::class, $settled);
        $this->assertSame(ProductionSlotStatus::Failed, $settled->status);
        $this->assertSame('GENERATION_FAILED', $settled->outcome_reason);
        $this->assertSame(['primary', 'secondary'], $settled->attempted());

        // Two suppliers, two generations. Not three, and not a loop.
        $this->assertSame(2, MusicGeneration::query()->count());
    }

    #[Test]
    public function a_failed_generation_with_nobody_else_fails_the_occasion(): void
    {
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Failed])->save();

        $settled = $this->settler()->handle($slot->refresh());

        $this->assertInstanceOf(ProductionSlot::class, $settled);
        $this->assertSame(ProductionSlotStatus::Failed, $settled->status);
        $this->assertSame('GENERATION_FAILED', $settled->outcome_reason);
    }

    // ------------------------------------------------ closing an occasion honestly

    #[Test]
    public function a_finished_generation_completes_the_occasion(): void
    {
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Completed])->save();

        $settled = $this->settler()->handle($slot->refresh());

        $this->assertInstanceOf(ProductionSlot::class, $settled);
        $this->assertSame(ProductionSlotStatus::Completed, $settled->status);
        $this->assertSame('GENERATION_COMPLETED', $settled->outcome_reason);

        // Completed means the supplier finished, never that a track exists.
        // Generated audio joins the review queue like any imported file.
        $this->assertSame(0, Track::query()->count());
    }

    #[Test]
    public function an_occasion_whose_generation_is_still_running_is_left_alone(): void
    {
        // A slot settled while its generation was still running would say the
        // occasion was over before the audio existed.
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Processing])->save();

        $this->assertNull($this->settler()->handle($slot->refresh()));
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    #[Test]
    public function a_cancelled_generation_cancels_the_occasion_rather_than_failing_it(): void
    {
        // Somebody called it off. A screen that filed that under failures would
        // hide the one gap the operator already knows about.
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Cancelled])->save();

        $settled = $this->settler()->handle($slot->refresh());

        $this->assertInstanceOf(ProductionSlot::class, $settled);
        $this->assertSame(ProductionSlotStatus::Cancelled, $settled->status);
        $this->assertTrue($settled->status->wasSetByAPerson());
    }

    #[Test]
    public function a_settled_occasion_is_not_settled_again(): void
    {
        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Completed])->save();

        $this->settler()->handle($slot->refresh());
        $first = $slot->refresh()->settled_at;

        $this->assertNull($this->settler()->handle($slot->refresh()));
        $this->assertEquals($first, $slot->refresh()->settled_at);
    }

    #[Test]
    public function a_claim_with_no_generation_is_left_for_a_reaper_rather_than_failed(): void
    {
        // A worker died between the claim and the request. The occasion has not
        // been spent, so failing it would throw away work nobody did.
        $slot = $this->dueSlot();
        $this->app->make(ClaimProductionSlot::class)->claim($slot, 'dead-worker');

        $this->assertNull($this->settler()->handle($slot->refresh()));
        $this->assertSame(ProductionSlotStatus::Claimed, $slot->refresh()->status);
    }

    // ------------------------------------------------ a skip is not a failure

    #[Test]
    public function a_reached_ceiling_is_an_occasion_that_passed_and_not_one_that_broke(): void
    {
        config(['generation.limits.daily' => 1]);

        MusicGeneration::query()->create([
            'provider' => 'primary',
            'status' => MusicGenerationStatus::Completed,
            'prompt' => 'the one that left',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => now()->subHour(),
        ]);

        $slot = $this->dueSlot();

        $this->assertNull($this->producer()->handle($slot, 'test-worker'));

        $slot->refresh();

        // Skipped. The window is rolling and will make room on its own; a
        // failure list full of these is a failure list nobody reads.
        $this->assertSame(ProductionSlotStatus::Skipped, $slot->status);
        $this->assertSame('CEILING_REACHED', $slot->outcome_reason);
    }

    #[Test]
    public function a_second_supplier_is_not_a_way_around_the_ceiling(): void
    {
        // The ceiling belongs to the installation, not to a supplier. Moving
        // the occasion elsewhere to get past it would defeat it, so a ceiling
        // refusal stops the loop rather than advancing it.
        $this->useProviders('primary', 'secondary');

        config(['generation.limits.daily' => 1]);

        MusicGeneration::query()->create([
            'provider' => 'primary',
            'status' => MusicGenerationStatus::Completed,
            'prompt' => 'the one that left',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => now()->subHour(),
        ]);

        $slot = $this->dueSlot();

        $this->assertNull($this->producer()->handle($slot, 'test-worker'));

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Skipped, $slot->status);
        // One supplier tried, not both. The second was never asked.
        $this->assertSame(['primary'], $slot->attempted());
    }

    #[Test]
    public function a_supplier_nobody_configured_is_a_failure_somebody_has_to_fix(): void
    {
        // Unlike a ceiling, this does not resolve itself. It belongs in the
        // list an operator reads.
        $this->useProviders();
        config(['generation.default' => MusicGenerationManager::DISABLED, 'generation.preference' => []]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $slot = $this->dueSlot();

        $this->assertNull($this->producer()->handle($slot, 'test-worker'));

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Failed, $slot->status);
        $this->assertSame('PROVIDER_UNAVAILABLE', $slot->outcome_reason);
    }

    #[Test]
    public function a_supplier_that_cannot_do_the_work_is_passed_over_before_it_is_blamed(): void
    {
        // `primary` cannot sing supplied lyrics and this request does not
        // supply any, so it serves. The point is that the requirement is
        // derived and checked at all.
        $this->useProviders('primary');
        $this->app->make(MusicGenerationManager::class)->register(
            'primary',
            (new FakeMusicGenerationProvider('primary'))->supporting([GenerationCapability::TextToMusic]),
        );

        $slot = $this->dueSlot();

        $this->assertInstanceOf(MusicGeneration::class, $this->producer()->handle($slot, 'test-worker'));
    }

    // ------------------------------------------------ the production path

    #[Test]
    public function the_command_is_what_does_all_of_this_in_production(): void
    {
        // WHO CALLS THIS IN PRODUCTION: `sanitube:production:run`, registered
        // by ProductionServiceProvider. No command, no production engine.
        $this->dueSlot();

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('Settled 0 occasion(s)')
            ->expectsOutputToContain('Requested 1 generation(s)')
            ->assertSuccessful();

        $this->assertSame(1, MusicGeneration::query()->count());
        Queue::assertPushed(SubmitMusicGenerationJob::class);

        $slot = ProductionSlot::query()->firstOrFail();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertNotNull($slot->music_generation_id);
    }

    #[Test]
    public function the_command_settles_what_is_in_flight_before_it_starts_anything(): void
    {
        // The order is what makes a bad afternoon at one supplier recover in
        // the same run rather than the next one.
        $this->useProviders('primary', 'secondary');

        $slot = $this->dueSlot();
        $generation = $this->producer()->handle($slot, 'test-worker');

        $this->assertInstanceOf(MusicGeneration::class, $generation);
        $generation->forceFill(['status' => MusicGenerationStatus::Failed])->save();

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('Settled 1 occasion(s)')
            ->assertSuccessful();

        $slot->refresh();

        $this->assertSame(ProductionSlotStatus::Claimed, $slot->status);
        $this->assertSame(['primary', 'secondary'], $slot->attempted());
    }

    #[Test]
    public function settle_only_reconciles_and_starts_nothing(): void
    {
        // For an operator who wants the recovery loop running while they decide
        // whether to trust the spending one.
        $this->dueSlot();

        $this->artisan('sanitube:production:run', ['--settle-only' => true])
            ->expectsOutputToContain('Nothing new was started')
            ->assertSuccessful();

        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_command_stops_when_the_platform_is_paused(): void
    {
        // The one place ignoring a pause would cost an operator money rather
        // than time.
        $this->dueSlot();
        $this->app->make(BackgroundWork::class)->pause(null, 'MAINTENANCE');

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('BACKGROUND_WORK_PAUSED')
            ->assertFailed();

        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_occasion_scheduled_for_later_is_not_work_waiting(): void
    {
        $slot = $this->dueSlot();
        $slot->forceFill(['scheduled_for' => now()->addWeek()])->save();

        $this->artisan('sanitube:production:run')
            ->expectsOutputToContain('Requested 0 generation(s)')
            ->assertSuccessful();

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function running_the_command_twice_produces_one_request_for_one_occasion(): void
    {
        // The claim is what enforces it, and this proves the command respects
        // it rather than re-reading the row.
        $this->dueSlot();

        $this->artisan('sanitube:production:run')->assertSuccessful();
        $this->artisan('sanitube:production:run')->assertSuccessful();

        $this->assertSame(1, MusicGeneration::query()->count());
    }

    // ------------------------------------------------ helpers

    /**
     * @param  string  ...$names  suppliers to configure, the first being the default
     */
    private function useProviders(string ...$names): void
    {
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->forgetInstance(SelectGenerationProvider::class);

        if ($names === []) {
            return;
        }

        config([
            'generation.default' => $names[0],
            'generation.preference' => $names,
        ]);

        foreach ($names as $name) {
            config(['generation.providers.'.$name => ['driver' => 'fake']]);
        }

        $manager = $this->app->make(MusicGenerationManager::class);

        foreach ($names as $name) {
            $manager->register($name, new FakeMusicGenerationProvider($name));
        }
    }

    private function producer(): ProduceForProductionSlot
    {
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(ProduceForProductionSlot::class);
    }

    private function settler(): SettleProductionSlot
    {
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(SettleProductionSlot::class);
    }

    private function dueSlot(
        AutonomyMode $mode = AutonomyMode::AutonomousGeneration,
        ?EditorialProfile $profile = null,
    ): ProductionSlot {
        // A target and an attributable artist, because PROD-003's inventory
        // refuses without both -- "I cannot tell what you have" outranks every
        // other answer, and a fixture that skipped them would test the refusal
        // rather than the work.
        $profile ??= $this->app->make(WriteEditorialProfile::class)->create([
            'name' => 'Imprint '.bin2hex(random_bytes(4)),
            'default_artist_id' => Artist::factory()->create()->id,
        ]);

        $plan = $this->app->make(WriteProductionPlan::class)->create(
            $profile,
            [
                'name' => 'Autumn '.bin2hex(random_bytes(3)),
                'autonomy_mode' => $mode,
                'cadence_days' => 7,
                'target_track_count' => 10,
            ],
        );

        $this->assertInstanceOf(ProductionPlan::class, $plan);

        $opened = $this->app->make(OpenProductionSlots::class)->handle();
        $slot = end($opened);

        $this->assertInstanceOf(ProductionSlot::class, $slot);

        return $slot;
    }
}
