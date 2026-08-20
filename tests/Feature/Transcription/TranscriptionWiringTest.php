<?php

declare(strict_types=1);

namespace Tests\Feature\Transcription;

use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Deduplication\Enums\DuplicateBasis;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Transcription\Enums\TranscriptionRefusal;
use SaniTube\Transcription\Events\AssetTranscribed;
use SaniTube\Transcription\Jobs\TranscribeAssetJob;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Services\RequestTranscription;
use SaniTube\Transcription\Services\TranscribeAsset;
use SaniTube\Transcription\Services\TranscriptionEligibility;
use SaniTube\Transcription\Testing\FakeTranscriptionProvider;
use SaniTube\Transcription\TranscriptionManager;
use Tests\TestCase;

/**
 * TRN-003 — the wiring, which is the whole ticket.
 *
 * TRN-001 built the contract and the storage; TRN-002 built the OpenAI adapter
 * and proved a real request leaves for the documented endpoint. **Nothing
 * called either of them.** `TranscribeAsset` had no upstream caller at all — no
 * event, no job, no command, no route — and PIPE-001's grep said so out loud
 * one ticket earlier. On a real installation no asset would ever have been
 * transcribed, and both of those tickets would have passed their own tests
 * forever.
 *
 * So these tests are written against the **seams** rather than the services:
 * what does storing an asset actually cause, what does pressing the button
 * actually queue, and what does the queued job actually do when it runs.
 *
 * The recurring theme is that this is the one enrichment that costs money per
 * file. Most of what follows is about *not* spending it.
 */
final class TranscriptionWiringTest extends TestCase
{
    use RefreshDatabase;

    private FakeTranscriptionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // Made to return words. The fake's default is an empty result, and
        // TRN-001 deliberately does not store one -- an empty transcript would
        // assert this asset contains no speech, which nobody established.
        $this->provider = (new FakeTranscriptionProvider)->willReturnText('a line somebody sang');

        // The manager reads its default once, at construction.
        $this->app->forgetInstance(TranscriptionManager::class);
        $this->app->instance(TranscriptionManager::class, $manager = new TranscriptionManager([], 'fake'));
        $manager->register('fake', $this->provider);
    }

    // ------------------------------------------------ automatic is off

    #[Test]
    public function storing_an_asset_does_not_spend_money_by_default(): void
    {
        // The default that matters most in this ticket. Deduplication runs on
        // every stored asset because comparing checksums is free; this does
        // not, because it is not. A library of four thousand masters must not
        // become four thousand paid calls because somebody uploaded a folder.
        Queue::fake();

        // Asserted from `.env.example` as well as from the resolved config,
        // because CI and every fresh install copy that file -- AI-001 learned
        // the hard way that what ships in it is the value that actually runs.
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertArrayHasKey('SANITUBE_TRANSCRIPTION_AUTOMATIC', $values);
        $this->assertSame('false', $values['SANITUBE_TRANSCRIPTION_AUTOMATIC']);
        $this->assertFalse((bool) config('transcription.automatic'));

        $this->asset();

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    #[Test]
    public function an_installation_that_asked_for_automatic_transcription_gets_it(): void
    {
        // The other half. Off by default is a decision, not an absence: an
        // operator who turns it on must actually get transcription, or the
        // switch is decoration.
        Queue::fake();
        config(['transcription.automatic' => true]);

        $asset = $this->asset();

        Queue::assertPushed(
            TranscribeAssetJob::class,
            static fn (TranscribeAssetJob $job): bool => $job->assetUuid === $asset->uuid,
        );
    }

    #[Test]
    public function automatic_transcription_does_not_fail_an_upload_when_the_platform_is_paused(): void
    {
        // An upload that already succeeded must not be reported as failed
        // because an optional enrichment was refused. The gate still holds --
        // nothing is queued -- but the exception does not escape into the
        // store.
        Queue::fake();
        config(['transcription.automatic' => true]);
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $asset = $this->asset();

        $this->assertTrue($asset->exists);
        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    // --------------------------------------------------- the explicit ask

    #[Test]
    public function a_catalogue_user_can_ask_for_one_asset_over_http(): void
    {
        // **WHO CALLS THIS IN PRODUCTION?** A person, through this route. The
        // request substitutes nothing: it goes through the real controller,
        // the real service and the real gates, and the assertion is on what
        // reached the queue.
        Queue::fake();
        $asset = $this->asset();

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription', ['language' => 'FR'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            TranscribeAssetJob::class,
            static fn (TranscribeAssetJob $job): bool => $job->assetUuid === $asset->uuid
                && $job->languageHint === 'fr',
        );
    }

    #[Test]
    public function a_member_cannot_spend_the_installations_money(): void
    {
        // The guard is on the route, so it cannot be lost by a controller
        // being rewritten. Causing a paid call to a third party is a
        // privilege even though reading a transcript is not.
        Queue::fake();
        $asset = $this->asset();

        $this->actingAs($this->user(UserRole::Member))
            ->post('/assets/'.$asset->uuid.'/transcription')
            ->assertForbidden();

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    #[Test]
    public function a_refusal_comes_back_as_a_code_rather_than_a_sentence(): void
    {
        Queue::fake();
        $asset = $this->asset();
        $this->trash($asset);

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription')
            ->assertRedirect()
            ->assertSessionHasErrors(['transcription' => TranscriptionRefusal::AssetTrashed->value]);

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    #[Test]
    public function the_pause_switch_reaches_the_button(): void
    {
        Queue::fake();
        $asset = $this->asset();
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription')
            ->assertRedirect()
            ->assertSessionHasErrors(['transcription' => 'BACKGROUND_WORK_PAUSED']);

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    #[Test]
    public function a_language_hint_must_be_a_code_and_not_a_sentence(): void
    {
        Queue::fake();
        $asset = $this->asset();

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/transcription', ['language' => 'probably French I think'])
            ->assertSessionHasErrors('language');

        Queue::assertNotPushed(TranscribeAssetJob::class);
    }

    // ------------------------------------------------------ eligibility

    #[Test]
    public function an_installation_with_no_supplier_refuses_before_anything_else(): void
    {
        // The ordinary state of a fresh install, and it must read as an
        // ordinary state. Asked first so that an operator with no key is told
        // that, rather than being told their artwork has no speech in it.
        $this->app->forgetInstance(TranscriptionManager::class);
        $this->app->instance(TranscriptionManager::class, new TranscriptionManager([], TranscriptionManager::DISABLED));

        $this->assertSame(
            TranscriptionRefusal::ProviderUnavailable,
            $this->eligibility()->refusalFor($this->asset()),
        );
    }

    #[Test]
    public function nothing_without_speech_in_it_is_ever_sent(): void
    {
        foreach ([AssetKind::Artwork, AssetKind::Document, AssetKind::DistributionExport] as $kind) {
            $this->assertSame(
                TranscriptionRefusal::NotAudio,
                $this->eligibility()->refusalFor($this->asset($kind)),
                sprintf('%s must not be sent to a speech supplier.', $kind->value),
            );
        }

        foreach ([AssetKind::AudioMaster, AssetKind::AudioDerivative, AssetKind::Preview, AssetKind::Stem] as $kind) {
            $this->assertNull(
                $this->eligibility()->refusalFor($this->asset($kind)),
                sprintf('%s can hold speech and must be eligible.', $kind->value),
            );
        }
    }

    #[Test]
    public function a_trashed_asset_is_never_transcribed(): void
    {
        // Paying to transcribe a file a person has already set aside is the
        // clearest waste available.
        $asset = $this->asset();
        $this->trash($asset);

        $this->assertSame(TranscriptionRefusal::AssetTrashed, $this->eligibility()->refusalFor($asset));
    }

    #[Test]
    public function an_asset_whose_bytes_are_not_settled_is_not_sent(): void
    {
        // A pending asset is one whose write is still happening. A supplier
        // reading a truncated object returns a confident transcript of half a
        // recording, which is worse than no transcript.
        $storage = $this->app->make(AssetStorageService::class);
        $pending = $storage->register(AssetKind::AudioMaster, 'still-arriving.wav');

        $this->assertSame(TranscriptionRefusal::AssetNotSettled, $this->eligibility()->refusalFor($pending));
    }

    #[Test]
    public function a_confirmed_duplicate_is_not_paid_for_twice(): void
    {
        // And **only** a confirmed one. A proposed finding is a question
        // nobody has answered; refusing on one would let an unreviewed
        // similarity score decide that a recording is never transcribed.
        $original = $this->asset();
        $copy = $this->asset();

        $relation = $this->relation($copy, $original, DuplicateDecision::Proposed);
        $this->assertNull($this->eligibility()->refusalFor($copy));

        $relation->forceFill(['decision' => DuplicateDecision::Confirmed])->save();

        $this->assertSame(TranscriptionRefusal::ConfirmedDuplicate, $this->eligibility()->refusalFor($copy));

        // The other side of the pair stays eligible, so the recording is not
        // left with no transcript at all.
        $this->assertNull($this->eligibility()->refusalFor($original));
    }

    #[Test]
    public function an_asset_already_transcribed_is_not_asked_about_again(): void
    {
        $asset = $this->asset();

        $this->assertNull($this->eligibility()->refusalFor($asset));

        $this->app->make(TranscribeAsset::class)->handle($asset);

        $this->assertSame(TranscriptionRefusal::AlreadyHeld, $this->eligibility()->refusalFor($asset));
    }

    // ------------------------------------------------------------ the job

    #[Test]
    public function the_job_transcribes_and_announces_it(): void
    {
        $asset = $this->asset();

        Event::fake([AssetTranscribed::class]);

        (new TranscribeAssetJob($asset->uuid, 'fr'))->handle(
            $this->app->make(TranscribeAsset::class),
            $this->eligibility(),
        );

        $this->assertSame(1, Transcript::query()->count());
        Event::assertDispatched(AssetTranscribed::class);
    }

    #[Test]
    public function the_job_checks_again_because_a_queue_is_a_delay(): void
    {
        // The check that actually protects the bill. Between the request and
        // the work, somebody trashed the asset -- and the job is the last
        // point at which noticing that is free rather than billed.
        $asset = $this->asset();
        $this->trash($asset);

        (new TranscribeAssetJob($asset->uuid))->handle(
            $this->app->make(TranscribeAsset::class),
            $this->eligibility(),
        );

        $this->assertSame(0, $this->provider->calls);
        $this->assertSame(0, Transcript::query()->count());
    }

    #[Test]
    public function a_job_for_an_asset_that_no_longer_exists_is_not_a_failure(): void
    {
        (new TranscribeAssetJob('a-uuid-that-was-never-registered'))->handle(
            $this->app->make(TranscribeAsset::class),
            $this->eligibility(),
        );

        $this->assertSame(0, $this->provider->calls);
    }

    // -------------------------------------------------------- the backlog

    #[Test]
    public function the_backlog_command_queues_only_what_is_eligible(): void
    {
        Queue::fake();

        $wanted = $this->asset();
        $artwork = $this->asset(AssetKind::Artwork);
        $trashed = $this->asset();
        $this->trash($trashed);

        $this->artisan('sanitube:transcription:backlog')
            ->expectsOutputToContain('Queued 1 asset(s)')
            ->assertSuccessful();

        Queue::assertPushed(TranscribeAssetJob::class, 1);
        Queue::assertPushed(
            TranscribeAssetJob::class,
            static fn (TranscribeAssetJob $job): bool => $job->assetUuid === $wanted->uuid,
        );

        $this->assertNotSame($artwork->uuid, $trashed->uuid);
    }

    #[Test]
    public function the_limit_is_the_cost_control_it_looks_like(): void
    {
        // `--limit` is not an escape hatch on this command, it is the point of
        // it. A first sweep over a large library should queue a bounded number
        // of paid calls, be looked at, and be run again -- rather than
        // discovering after four thousand of them that the language hint was
        // wrong.
        Queue::fake();

        $this->asset();
        $this->asset();
        $this->asset();

        $this->artisan('sanitube:transcription:backlog --limit=2')
            ->expectsOutputToContain('Queued 2 asset(s)')
            ->assertSuccessful();

        Queue::assertPushed(TranscribeAssetJob::class, 2);

        // And zero means all, as the signature says. A `--limit=0` that
        // quietly queued nothing would be the most expensive kind of silent
        // no-op: an operator would conclude the library was already done.
        Queue::fake();

        $this->artisan('sanitube:transcription:backlog --limit=0')
            ->expectsOutputToContain('Queued 3 asset(s)')
            ->assertSuccessful();

        Queue::assertPushed(TranscribeAssetJob::class, 3);
    }

    #[Test]
    public function a_dry_run_enqueues_nothing(): void
    {
        Queue::fake();
        $this->asset();

        $this->artisan('sanitube:transcription:backlog --dry-run')
            ->expectsOutputToContain('would be queued')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_backlog_command_names_the_missing_supplier_rather_than_reporting_nothing_to_do(): void
    {
        // "0 queued" and "you have no supplier" look identical to an operator
        // and mean entirely different things.
        Queue::fake();
        $this->app->forgetInstance(TranscriptionManager::class);
        $this->app->instance(TranscriptionManager::class, new TranscriptionManager([], TranscriptionManager::DISABLED));
        $this->asset();

        $this->artisan('sanitube:transcription:backlog')
            ->expectsOutputToContain(TranscriptionRefusal::ProviderUnavailable->value)
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_backlog_command_asks_the_ceiling_for_the_whole_batch(): void
    {
        // **The size of the batch is the question**, not merely whether the
        // gate exists. Admitting one job at a time would let a sweep walk
        // straight past a ceiling one asset at a time, and every individual
        // admission would legitimately succeed -- which is the exact failure
        // OPS-002 exists to stop, and the exact failure a pause-switch test
        // cannot see.
        Queue::fake();
        config(['operations.backlog.ceiling' => 2]);

        $this->asset();
        $this->asset();
        $this->asset();

        $this->artisan('sanitube:transcription:backlog')
            ->expectsOutputToContain('BACKLOG_SATURATED')
            ->assertFailed();

        // Nothing, rather than the two that would have fitted. A half-enqueued
        // batch is work stranded where nobody is watching.
        Queue::assertNothingPushed();

        // And the ceiling is a ceiling rather than a blanket refusal: room for
        // three admits three.
        config(['operations.backlog.ceiling' => 3]);

        $this->artisan('sanitube:transcription:backlog')->assertSuccessful();

        Queue::assertPushed(TranscribeAssetJob::class, 3);
    }

    #[Test]
    public function the_backlog_command_stops_when_the_platform_is_paused(): void
    {
        // The other gate, asked separately so an operator can see which one
        // stopped them.
        Queue::fake();
        $this->asset();
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $this->artisan('sanitube:transcription:backlog')
            ->expectsOutputToContain('BACKGROUND_WORK_PAUSED')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_request_service_refuses_rather_than_queueing_when_the_platform_is_paused(): void
    {
        Queue::fake();
        $asset = $this->asset();
        $this->app->make(BackgroundWork::class)->pause(null, 'a test');

        $this->expectException(WorkRefused::class);

        try {
            $this->app->make(RequestTranscription::class)->for($asset);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    // ------------------------------------------------------------ fixtures

    private function eligibility(): TranscriptionEligibility
    {
        return $this->app->make(TranscriptionEligibility::class);
    }

    /**
     * A stored asset of any kind.
     *
     * Derived kinds are given a master to hang off, because the observer
     * refuses one without a parent -- a derivative nobody can trace back to a
     * master is not a thing this platform stores.
     */
    private function asset(AssetKind $kind = AssetKind::AudioMaster): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $parent = $kind->requiresParent() ? $this->asset() : null;

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register($kind, 'master.wav', $parent), $stream);
    }

    private function trash(Asset $asset): void
    {
        $this->app->make(TrashAsset::class)->trash($asset, $this->user(), 'DUPLICATE');
        $asset->refresh();
    }

    private function relation(Asset $asset, Asset $related, DuplicateDecision $decision): DuplicateRelation
    {
        return DuplicateRelation::query()->create([
            'asset_id' => $asset->id,
            'related_asset_id' => $related->id,
            'level' => DuplicateLevel::ExactDuplicate,
            'basis' => DuplicateBasis::Checksum,
            'decision' => $decision,
            'detected_at' => now(),
        ]);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::query()->create([
            'name' => 'A cataloguer',
            'email' => 'c-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
