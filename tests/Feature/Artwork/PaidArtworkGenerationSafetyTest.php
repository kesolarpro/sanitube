<?php

declare(strict_types=1);

namespace Tests\Feature\Artwork;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Enums\ArtworkGenerationStatus;
use SaniTube\Artwork\Enums\ArtworkRefusal;
use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageSize;
use SaniTube\Artwork\Jobs\GenerateArtworkJob;
use SaniTube\Artwork\Models\ArtworkGeneration;
use SaniTube\Artwork\Services\SubmitArtworkGeneration;
use SaniTube\Artwork\Testing\FakeImageMeasurer;
use SaniTube\Artwork\Testing\FakeImageProvider;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * ART-004 — a paid call gets a ceiling before it gets a button.
 *
 * ART-003 measured whether cover generation was possible on an installation and
 * said so, and **deliberately stopped short of wiring a trigger**: `GenerateArtwork`
 * had no record, nothing counted its requests, and therefore no ceiling could
 * exist. Wiring a paid generator to a button without one would have been the
 * one thing the cost rules forbid.
 *
 * So most of what is asserted here is what the platform **refuses to do**, and
 * the single most important claim is about what a refusal costs:
 *
 * **A request refused before submission has no `submitted_at`, and therefore
 * never counts against the ceiling that produced it.** Counting by outcome
 * would get this wrong in the direction somebody pays for: a ceiling that
 * counted its own refusals would lock an installation out permanently after
 * one bad afternoon.
 *
 * **This is operational safety, not finance.** Requests are counted because
 * requests are what the platform can observe. There is no price, no currency,
 * no balance and no invoice anywhere in it.
 */
final class PaidArtworkGenerationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // A provider that can actually reach the requirement, so the tests
        // below are about the ceilings rather than about ART-002's mismatch.
        config(['artwork.requirements.minimum_pixels' => 1000, 'artwork.requirements.must_be_square' => true]);

        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer(
            new ArtworkMeasurement(3000, 3000, 'image/jpeg', 900_000, channels: 3),
        ));

        Queue::fake();
    }

    // --------------------------------------------------------------- access

    #[Test]
    public function a_read_only_member_cannot_spend_money_from_this_screen(): void
    {
        $this->withProvider();

        $release = Release::factory()->create();

        $this->actingAs($this->user(UserRole::Member))
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertForbidden();

        $this->assertSame(0, ArtworkGeneration::query()->count());
    }

    // ------------------------------------------------------- the production path

    #[Test]
    public function asking_from_the_release_screen_records_the_request_and_queues_the_work(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the release builder's artwork section,
        // POSTing to /releases/{uuid}/cover/generate.
        //
        // Nothing is generated inside the request: a provider call inside an
        // HTTP request is one that half-runs whenever somebody navigates away,
        // and this one costs money when it does.
        $this->withProvider();

        $release = Release::factory()->create();
        $operator = $this->user();

        $this->actingAs($operator)
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $generation = ArtworkGeneration::query()->firstOrFail();

        $this->assertSame(ArtworkGenerationStatus::Pending, $generation->status);
        $this->assertSame($release->id, $generation->release_id);
        $this->assertSame($operator->getKey(), $generation->requested_by);
        $this->assertNull($generation->submitted_at, 'Recording an intent must cost nothing.');

        Queue::assertPushed(
            GenerateArtworkJob::class,
            static fn (GenerateArtworkJob $job): bool => $job->generationUuid === $generation->uuid,
        );
    }

    #[Test]
    public function the_job_produces_a_verified_asset_and_records_where_it_came_from(): void
    {
        $this->withProvider();

        $generation = $this->request();

        $this->runJob($generation);

        $generation->refresh();

        $this->assertSame(ArtworkGenerationStatus::Completed, $generation->status);
        $this->assertNotNull($generation->submitted_at, 'A completed request reached a provider.');
        $this->assertNotNull($generation->completed_at);
        $this->assertSame(1, $generation->attempts);
        $this->assertSame('fake-model-1', $generation->model, 'Provenance: which model made this.');

        // A generated cover is an Asset like any other, measured by ART-001 the
        // same way. It never becomes a release cover on its own.
        $asset = Asset::query()->findOrFail($generation->asset_id);

        $this->assertSame(AssetStatus::Verified, $asset->status);
        $this->assertNull($generation->release->refresh()->cover_asset_id, 'Generating is not choosing.');
    }

    // ---------------------------------------------------------- the ceilings

    #[Test]
    public function a_reached_ceiling_refuses_before_anything_is_sent(): void
    {
        config(['artwork.limits.daily' => 1]);

        $this->withProvider();
        $this->submittedRequest();

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasErrors(['artwork' => ArtworkRefusal::CeilingReached->value]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_refusal_never_counts_against_the_ceiling_that_produced_it(): void
    {
        // The claim this whole ticket turns on. A request refused before
        // submission cost nothing, so counting it would lock an installation
        // out permanently after one bad afternoon.
        config(['artwork.limits.daily' => 2]);

        $this->withProvider(available: false);

        $release = Release::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user())
                ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
                ->assertSessionHasErrors(['artwork' => ArtworkRefusal::NoProvider->value]);
        }

        // Five refusals later, the provider comes back and the ceiling is
        // untouched: nothing was ever sent.
        $this->withProvider();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_recorded_request_that_never_reached_a_provider_does_not_count_either(): void
    {
        // The sharper half of the same claim, and the one a mutation caught.
        // The test above proves that a refusal *before* a row exists costs
        // nothing — which is also true if the guard counted rows by
        // `created_at`, because there is no row.
        //
        // This is the case that tells the two apart: requests that were
        // recorded, sat in a queue, and were refused when they came to leave.
        // They exist as rows and they cost nothing, so a guard counting
        // `created_at` would lock the installation out on work it never did.
        // The breaker is switched off here so this test is about the ceiling
        // and nothing else. Five failures in a row would otherwise open it —
        // correctly, which is what `a_provider_that_keeps_failing_is_left_alone`
        // asserts — and the refusal would come from the wrong guard.
        config(['artwork.limits.daily' => 3, 'artwork.circuit.consecutive_failures' => 0]);

        $this->withProvider();

        for ($i = 0; $i < 5; $i++) {
            ArtworkGeneration::query()->create([
                'provider' => 'fake',
                'status' => ArtworkGenerationStatus::Failed,
                'failure_code' => ArtworkRefusal::GenerationPaused,
                'prompt' => 'Recorded, queued, refused before it left.',
                'attempts' => 0,
                // The whole point: no `submitted_at`.
                'completed_at' => Carbon::now(),
            ]);
        }

        $this->assertSame(5, ArtworkGeneration::query()->count(), 'Five rows exist.');

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_ceiling_is_re_read_when_the_request_would_actually_leave(): void
    {
        // A person clicks; a queue runs the job hours later with a hundred
        // siblings ahead of it. The ceiling that matters is the one true at the
        // moment the request would leave, not the one true when they clicked.
        $this->withProvider();

        $generation = $this->request();

        config(['artwork.limits.daily' => 1]);
        $this->submittedRequest();

        $this->runJob($generation);

        $generation->refresh();

        $this->assertSame(ArtworkGenerationStatus::Failed, $generation->status);
        $this->assertSame(ArtworkRefusal::CeilingReached, $generation->failure_code);
        $this->assertNull($generation->submitted_at, 'Refused before leaving, so it cost nothing.');
    }

    #[Test]
    public function no_ceiling_configured_means_no_ceiling(): void
    {
        // Zero is the shipped default. A platform that refused generation out
        // of the box would be one whose first experience of the feature is a
        // refusal.
        config(['artwork.limits.daily' => 0, 'artwork.limits.weekly' => 0, 'artwork.limits.monthly' => 0]);

        $this->withProvider();

        for ($i = 0; $i < 3; $i++) {
            $this->submittedRequest();
        }

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();
    }

    // ----------------------------------------------------- the circuit breaker

    #[Test]
    public function a_provider_that_keeps_failing_is_left_alone(): void
    {
        config(['artwork.circuit.consecutive_failures' => 3, 'artwork.circuit.cooldown_minutes' => 15]);

        $this->withProvider();
        $this->failedRequests(3);

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasErrors(['artwork' => ArtworkRefusal::ProviderCircuitOpen->value]);
    }

    #[Test]
    public function one_success_closes_the_breaker(): void
    {
        // Consecutive failures, not a failure rate. One success is evidence the
        // provider is answering again.
        config(['artwork.circuit.consecutive_failures' => 3]);

        $this->withProvider();
        $this->failedRequests(3);
        $this->submittedRequest(ArtworkGenerationStatus::Completed);

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_breaker_reopens_the_tap_after_the_cooldown(): void
    {
        // What makes it a breaker rather than a fuse. Without this an outage
        // would disable generation until somebody noticed.
        config(['artwork.circuit.consecutive_failures' => 3, 'artwork.circuit.cooldown_minutes' => 15]);

        $this->withProvider();
        $this->failedRequests(3, at: Carbon::now()->subMinutes(30));

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------- the switches

    #[Test]
    public function the_dedicated_switch_stops_generation(): void
    {
        config(['artwork.generation_enabled' => false]);

        $this->withProvider();

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasErrors(['artwork' => ArtworkRefusal::GenerationPaused->value]);
    }

    #[Test]
    public function the_global_background_pause_stops_generation_too(): void
    {
        // Both are honoured, and they answer different questions: "this
        // installation is doing nothing right now" and "this installation does
        // not generate covers".
        $this->withProvider();
        $this->app->make(BackgroundWork::class)->pause(null, 'Maintenance.');

        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasErrors(['artwork' => ArtworkRefusal::GenerationPaused->value]);
    }

    // --------------------------------------------------------------- the claim

    #[Test]
    public function running_the_same_job_twice_calls_the_provider_once(): void
    {
        // A queue redelivers, and a paid call is the worst thing to make twice.
        $provider = $this->withProvider();

        $generation = $this->request();

        $this->runJob($generation);
        $this->runJob($generation->refresh());

        $this->assertCount(1, $provider->requests, 'A redelivery must not spend again.');
        $this->assertSame(1, $generation->refresh()->attempts);
    }

    #[Test]
    public function a_request_another_worker_holds_is_left_alone(): void
    {
        $provider = $this->withProvider();

        $generation = $this->request();

        // Another worker claimed it a moment ago and is still working.
        $generation->forceFill(['claimed_at' => Carbon::now()])->save();

        $this->runJob($generation);

        $this->assertSame([], $provider->requests, 'A lost race is a success: the work is happening elsewhere.');
        $this->assertSame(ArtworkGenerationStatus::Pending, $generation->refresh()->status);
    }

    #[Test]
    public function an_expired_claim_can_be_taken_over(): void
    {
        // A worker killed mid-flight must not park a request for ever.
        config(['artwork.submission_claim_seconds' => 60]);

        $this->withProvider();

        $generation = $this->request();
        $generation->forceFill(['claimed_at' => Carbon::now()->subMinutes(30)])->save();

        $this->runJob($generation);

        $this->assertSame(ArtworkGenerationStatus::Completed, $generation->refresh()->status);
    }

    #[Test]
    public function a_request_that_vanished_between_queueing_and_running_is_not_a_failure(): void
    {
        (new GenerateArtworkJob('00000000-0000-7000-8000-000000000000'))
            ->handle($this->app->make(SubmitArtworkGeneration::class));

        $this->assertSame(0, ArtworkGeneration::query()->count());
    }

    // ----------------------------------------------------------- no finance

    #[Test]
    public function nothing_in_this_ticket_holds_money(): void
    {
        // Operational safety counts *requests*, because requests are what the
        // platform can observe. A ceiling that held a price would be the first
        // step towards an accounting system this product does not have.
        $source = '';

        foreach ([
            'src/Artwork/Services/ArtworkSpendGuard.php',
            'src/Artwork/Services/ArtworkCircuitBreaker.php',
            'src/Artwork/Services/RequestArtworkGeneration.php',
            'src/Artwork/Services/SubmitArtworkGeneration.php',
            'src/Artwork/Models/ArtworkGeneration.php',
        ] as $file) {
            $contents = file_get_contents(base_path($file));

            self::assertIsString($contents);

            // Comments stripped: these files discuss money in order to say they
            // hold none, and a guard that could not tell prose from code would
            // force that discussion out of the codebase.
            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $source .= is_array($token) ? $token[1] : $token;
            }
        }

        foreach (['price', 'currency', 'balance', 'invoice', 'royalt', 'payout', 'revenue'] as $word) {
            $this->assertStringNotContainsString($word, strtolower($source));
        }
    }

    // ----------------------------------------------------------- fixtures

    private function withProvider(bool $available = true): FakeImageProvider
    {
        $provider = new FakeImageProvider(
            image: new GeneratedImage(
                bytes: $this->jpegBytes(),
                mimeType: 'image/jpeg',
                model: 'fake-model-1',
            ),
            sizes: [new ImageSize(1024, 1024)],
            available: $available,
        );

        $this->app->instance(ImageProvider::class, $provider);

        return $provider;
    }

    private function request(): ArtworkGeneration
    {
        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/cover/generate', ['prompt' => 'A quiet winter morning'])
            ->assertSessionHasNoErrors();

        return ArtworkGeneration::query()->orderByDesc('id')->firstOrFail();
    }

    private function runJob(ArtworkGeneration $generation): void
    {
        (new GenerateArtworkJob($generation->uuid))
            ->handle($this->app->make(SubmitArtworkGeneration::class));
    }

    /**
     * A row that reached a provider, which is what the ceiling counts.
     */
    private function submittedRequest(
        ArtworkGenerationStatus $status = ArtworkGenerationStatus::Completed,
    ): ArtworkGeneration {
        return ArtworkGeneration::query()->create([
            'provider' => 'fake',
            'status' => $status,
            'prompt' => 'Already asked for.',
            'attempts' => 1,
            'submitted_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
        ]);
    }

    private function failedRequests(int $count, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();

        for ($i = 0; $i < $count; $i++) {
            ArtworkGeneration::query()->create([
                'provider' => 'fake',
                'status' => ArtworkGenerationStatus::Failed,
                'failure_code' => ArtworkRefusal::ProviderFailed,
                'prompt' => 'Asked and refused.',
                'attempts' => 1,
                'submitted_at' => $at,
                'completed_at' => $at,
            ]);
        }
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(64, 64);
        self::assertNotFalse($image);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
