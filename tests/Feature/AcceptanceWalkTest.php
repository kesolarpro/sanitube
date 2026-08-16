<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Deployment\Services\CreateBackup;
use SaniTube\Deployment\Services\RestoreBackup;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\FinalizeIngestionBatch;
use SaniTube\Ingestion\Services\ProcessIngestionItem;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;
use SaniTube\MusicGeneration\Services\SelectMusicGenerationResult;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\ReleaseMutationPolicy;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Releases\Services\ValidateRelease;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * One operator, from an empty catalogue to a delivered release.
 *
 * Every other test in this suite asks whether a *part* works. This one asks
 * whether the parts are still connected to each other, which is a different
 * question and the one nobody notices going wrong. A module can be green in
 * isolation and unreachable in practice: a candidate that no service will
 * promote because a status enum drifted, a release that validates but cannot
 * be built because the builder guards on something validation does not report,
 * a delivery that submits but whose local status maps to nothing.
 *
 * It also fixes the definition of "done" in code rather than in a document.
 * The V1 brief lists what an installation must be able to do; below, each of
 * those is a step in a walk, in the order a person actually performs them.
 * A capability that regresses to unreachable fails here even while its own
 * module's tests stay green.
 *
 * **What is deliberately faked, and why.** Storage is in-memory, the
 * distributor and the generation provider are the platform's own fakes, and
 * the queue is faked so that work is enqueued here and drained explicitly —
 * which is the real deployment shape, where a worker picks it up later. What
 * is *not* faked is every service under test: the walk goes through the same
 * ingestion, promotion, builder, validation and submission code a browser
 * reaches.
 *
 * **What this walk does not cover.** Installation itself, which cannot
 * meaningfully run against the database the suite is already connected to and
 * has its own tests; and the unknown-submission outcome, which lives on an
 * open branch awaiting review. Both are named here rather than left as silent
 * gaps.
 */
final class AcceptanceWalkTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private FakeDistributor $distributor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        config(['distribution.default' => 'fake']);
        $this->distributor = new FakeDistributor('fake', sandbox: false);
        $this->app->forgetInstance(DistributorManager::class);
        $this->app->make(DistributorManager::class)->register('fake', $this->distributor);

        // The test environment runs the queue inline, which would do the
        // import inside the request that started it — the one shape the
        // pipeline is designed never to have.
        Queue::fake();
    }

    #[Test]
    public function a_label_imports_a_library_reviews_it_and_delivers_a_release(): void
    {
        // ---------------------------------------------------- 1. the library
        //
        // Twelve rather than nine hundred: the claim under test is that the
        // walk holds together, and the claim that it holds at nine hundred is
        // about queueing, which ImportCommandTest asserts where it belongs.
        foreach (range(1, 12) as $n) {
            $this->store->put(sprintf('library/%02d.wav', $n), 'recording '.$n);
        }

        $batch = $this->app->make(StartIngestionBatch::class)->handle(
            source: IngestionSource::CloudImport,
            prefix: 'library',
        );

        $this->assertSame(12, $batch->total());

        // Nothing has entered the catalogue yet, and that is the load-bearing
        // promise of the import: a library is not a catalogue.
        $this->assertSame(0, Track::query()->count());

        $this->drainQueue();

        $this->assertSame(12, TrackCandidate::query()->count());
        $this->assertSame(0, Track::query()->count());

        // ------------------------------------------------ 2. a person reviews
        //
        // Three promoted, and the other nine left where they are. A review
        // queue that empties itself is not a review queue.
        $tracks = [];

        foreach (TrackCandidate::query()->orderBy('id')->take(3)->get() as $index => $candidate) {
            $tracks[] = $this->app->make(PromoteTrackCandidate::class)->handle(
                $candidate,
                ['title' => sprintf('Night Bus %d', $index + 1), 'language_code' => 'en'],
            );
        }

        $this->assertSame(3, Track::query()->count());
        $this->assertSame(
            9,
            TrackCandidate::query()->where('status', '!=', TrackCandidateStatus::Promoted->value)->count(),
        );

        // ------------------------------------------------- 3. the catalogue
        //
        // A promoted track is a catalogue entry with a public uuid and no
        // exposed database id — the identity rule the whole platform is built
        // on, checked here at the point a person first sees one.
        foreach ($tracks as $track) {
            $this->assertNotSame('', $track->uuid);
            $this->assertSame('uuid', $track->getRouteKeyName());
            $this->assertSame(AssetStatus::Verified, $track->masterAsset()->firstOrFail()->status);
        }

        // ------------------------------------- 3b. and it is not yet releasable
        //
        // A promoted track is a DRAFT. It carries no credits, and I3 refuses
        // READY without a primary artist — which is right: a recording with
        // nobody's name on it is not deliverable.
        //
        // Through the browser, which is the point. When this walk was first
        // written the catalogue was read-only: a label could promote a
        // candidate and then had no way to credit the track or mark it ready,
        // so every release built from it failed validation with
        // TRACK_NOT_RELEASABLE and the fix was unreachable from any screen.
        // CAT-002 closed that, and the walk goes through the same two routes a
        // person does.
        $label = Artist::factory()->create(['name' => 'The Label']);
        $operator = $this->operator();

        foreach ($tracks as $track) {
            $this->assertSame(TrackStatus::Draft, $track->status);
            $this->assertFalse($track->status->isReleasable());

            $this->actingAs($operator)
                ->post('/catalog/tracks/'.$track->uuid.'/credits', [
                    'artists' => [['uuid' => $label->uuid, 'role' => 'PRIMARY']],
                ])
                ->assertRedirect();

            $this->actingAs($operator)
                ->post('/catalog/tracks/'.$track->uuid.'/ready')
                ->assertRedirect();

            $this->assertTrue($track->refresh()->status->isReleasable());
        }

        // ----------------------------------------------- 4. building an EP
        $builder = $this->app->make(ReleaseBuilder::class);
        $release = $builder->createDraft('Night Bus', ReleaseType::Ep);

        foreach ($tracks as $track) {
            $release = $builder->addTrack($release->refresh(), $track);
        }

        $this->assertSame([1, 2, 3], $release->refresh()->trackEntries()
            ->orderBy('track_number')->pluck('track_number')->map(intval(...))->all());

        // --------------------------------------- 5. validation before credits
        //
        // Asked early, which is the point of having a validator separate from
        // readiness: a label wants to see what is missing long before it is
        // ready to commit.
        $validator = $this->app->make(ValidateRelease::class);
        $early = $validator->handle($release->refresh());

        $this->assertFalse($early->isValid());
        $this->assertTrue($early->has('PRIMARY_ARTIST_REQUIRED'));
        $this->assertTrue($early->has('COVER_REQUIRED'));

        // Every blocking issue is a code an interface can translate, not a
        // sentence it has to render. REL-002; asserted here because the walk
        // is where a raw code would actually reach a person.
        foreach ($early->errors() as $issue) {
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', $issue->code);
            $this->assertArrayHasKey($issue->code, $this->issueTranslations('en'));
        }

        // -------------------------------------------- 6. credits and artwork
        $builder->setArtists($release->refresh(), [
            ['artist' => $label, 'role' => ReleaseArtistRole::Primary],
        ]);

        $builder->setCover($release->refresh(), $this->cover());

        $release = $builder->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
            'label_name' => 'Night Bus Recordings',
            'catalogue_number' => 'NBR-001',
            'language_code' => 'en',
            'p_line' => '℗ 2026 Night Bus Recordings',
            'c_line' => '© 2026 Night Bus Recordings',
        ]);

        // ------------------------------------------------- 7. ready to deliver
        $ready = $validator->handle($release->refresh());
        $this->assertTrue($ready->isValid(), implode('; ', $ready->messages()));

        $release->markReady();
        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);

        // Committed packages stop being editable. A release a distributor is
        // about to receive must not quietly change underneath it.
        $this->assertFalse(ReleaseMutationPolicy::allowsStructuralChange($release->refresh()->status));

        // ------------------------------------ 8. identifiers, never invented
        //
        // Both the ISRCs and the UPC are supplied by the label. SaniTube mints
        // neither, and refuses the delivery rather than guessing — a recording
        // distributed elsewhere already has an ISRC, and inventing a second one
        // splits its reporting in every store that receives it.
        $submitter = $this->app->make(SubmitDelivery::class);
        $assign = $this->app->make(AssignExternalIdentifier::class);

        try {
            $submitter->handle($release->refresh(), 'fake');
            $this->fail('A release with no identifiers was delivered.');
        } catch (DistributionException $exception) {
            $this->assertSame('DISTRIBUTOR_REJECTED', $exception->reason);
        }

        foreach ($tracks as $index => $track) {
            $assign($track->refresh(), ExternalIdentifierType::Isrc, sprintf('GBAAA260000%d', $index + 1));
        }

        // Still refused: the tracks can be identified, the product cannot.
        try {
            $submitter->handle($release->refresh(), 'fake');
            $this->fail('A release with no product identifier was delivered.');
        } catch (DistributionException $exception) {
            $this->assertSame('DISTRIBUTOR_REJECTED', $exception->reason);
        }

        $assign($release->refresh(), ExternalIdentifierType::Upc, '088500000001');

        // --------------------------------------------------- 9. the delivery
        $delivery = $submitter->handle($release->refresh(), 'fake');

        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);
        $this->assertNotNull($delivery->external_release_id);
        $this->assertSame(1, $this->distributor->submitCalls);

        // -------------------------------------------- 10. and only once ever
        try {
            $submitter->handle($release->refresh(), 'fake');
            $this->fail('The same release was handed over twice.');
        } catch (DistributionException $exception) {
            $this->assertSame('ALREADY_SUBMITTED', $exception->reason);
        }

        $this->assertSame(1, $this->distributor->submitCalls);

        // ------------------------------------------------ 11. and it goes live
        $this->distributor->advance((string) $delivery->external_release_id, DeliveryStatus::Live);

        $synced = $submitter->sync($delivery->refresh());

        $this->assertSame(DistributionDeliveryStatus::Live, $synced->status);
        $this->assertNotNull($synced->live_at);

        // Every step left a record. The attempt history is what an operator
        // reads when something has gone wrong, and a delivery with no history
        // is one nobody can reconstruct.
        $this->assertGreaterThanOrEqual(2, $synced->attempts()->count());
    }

    #[Test]
    public function a_refusal_by_the_distributor_is_a_verdict_and_an_outage_is_not(): void
    {
        // The two failure shapes an operator has to tell apart, walked end to
        // end rather than asserted on a hand-built delivery row. Confusing
        // them is what makes somebody re-send a package a store already holds.
        $release = $this->deliverableRelease();
        $submitter = $this->app->make(SubmitDelivery::class);

        $this->distributor->down();

        $delivery = $submitter->handle($release, 'fake');

        // An outage. Nobody said no; nobody could be asked. Retryable, and the
        // same idempotency key goes back out.
        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);
        $this->assertTrue($delivery->status->isSubmittable());

        $key = $delivery->idempotency_key;

        $this->distributor->recover();
        $this->distributor->rejecting(['The cover artwork is below 3000x3000.']);

        try {
            $submitter->handle($release->refresh(), 'fake');
            $this->fail('A refused release was reported as delivered.');
        } catch (DistributionException $exception) {
            $this->assertSame('DISTRIBUTOR_REJECTED', $exception->reason);
        }

        // A verdict. Not retryable by simply pressing the button again — the
        // package has to change first.
        $this->assertSame($key, $delivery->refresh()->idempotency_key);
    }

    #[Test]
    public function a_generated_recording_joins_the_same_review_queue_as_an_imported_one(): void
    {
        // The claim that keeps the Studio honest: generation is a *source*, not
        // a shortcut. Nothing it produces reaches the catalogue without a
        // person, and the walk proves it by going all the way to a Track.
        $provider = new FakeMusicGenerationProvider;
        config(['generation.default' => 'fake']);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', $provider);

        $audio = new InMemoryGeneratedAudioReader;
        $this->app->instance(GeneratedAudioReader::class, $audio);

        $generation = $this->app->make(SubmitMusicGeneration::class)->handle(
            $this->app->make(StartMusicGeneration::class)->handle(
                prompt: 'A slow night bus through a wet city.',
                instrumental: true,
                providerName: 'fake',
            ),
        );

        $provider->complete((string) $generation->provider_job_id);
        $generation = $this->app->make(PollMusicGeneration::class)->handle($generation);

        $result = $generation->refresh()->results()->firstOrFail();

        $candidate = $this->app->make(SelectMusicGenerationResult::class)->handle($result);

        // The same settling step an imported candidate goes through. Generated
        // audio takes the identical path — that is the claim.
        $this->app->make(SettleCandidateAfterAnalysis::class)->handle($candidate);

        // A candidate, not a track. The whole point.
        $this->assertInstanceOf(TrackCandidate::class, $candidate);
        $this->assertSame(IngestionSource::Generated, $candidate->source);
        $this->assertSame(0, Track::query()->count());

        $track = $this->app->make(PromoteTrackCandidate::class)->handle(
            $candidate->refresh(),
            ['title' => 'Night Bus (generated)', 'is_instrumental' => true],
        );

        $this->assertSame(1, Track::query()->count());
        $this->assertSame(ContentLanguage::INSTRUMENTAL, $track->language_code);
    }

    #[Test]
    public function a_backup_taken_after_a_delivery_restores_the_catalogue_and_the_delivery(): void
    {
        // The last three capabilities in one step, because separately they
        // prove nothing an operator cares about. "The backup command runs" and
        // "the restore command runs" are both true of a system that loses the
        // delivery history — and the delivery history is the record of the one
        // irreversible thing the platform did.
        $destination = storage_path('framework/testing/walk-backup-'.bin2hex(random_bytes(4)));
        config(['backup.destination' => $destination]);

        try {
            $release = $this->deliverableRelease();
            $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

            $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);

            $reference = (string) $delivery->external_release_id;
            $trackUuid = (string) Track::query()->firstOrFail()->uuid;

            $backup = $this->app->make(CreateBackup::class)->handle('after-delivery');

            // Everything since the backup is gone: the catalogue, the release
            // and the record of what was handed over. This is the disaster the
            // whole of OPS-001 exists for.
            // In dependency order, as a cascade would take them.
            DistributionAttempt::query()->delete();
            DistributionDelivery::query()->delete();
            Release::query()->forceDelete();
            Track::query()->forceDelete();

            $this->assertSame(0, Track::query()->count());
            $this->assertSame(0, DistributionDelivery::query()->count());

            $this->app->make(RestoreBackup::class)->handle($backup['path'], confirmed: true);

            // The catalogue came back.
            $this->assertSame($trackUuid, (string) Track::query()->firstOrFail()->uuid);

            // And so did the fact that a distributor already holds it, under
            // the same reference. A restore that brought back the catalogue but
            // not the delivery would invite an operator to send it again.
            $restored = DistributionDelivery::query()->firstOrFail();

            $this->assertSame(DistributionDeliveryStatus::Submitted, $restored->status);
            $this->assertSame($reference, (string) $restored->external_release_id);
            $this->assertGreaterThanOrEqual(1, $restored->attempts()->count());
        } finally {
            $this->rmrf($destination);
        }
    }

    #[Test]
    public function every_locale_can_render_what_the_walk_produces(): void
    {
        // Six languages is a V1 capability, and the way it breaks is not that a
        // file is missing — the localisation gate catches that — but that a
        // key added for one screen never reaches the other five. Checking the
        // keys this walk actually reaches keeps that specific to what a person
        // sees on the path through the platform.
        $english = $this->issueTranslations('en');

        $this->assertGreaterThan(15, count($english));

        foreach (['fr', 'es', 'it', 'pt', 'de'] as $locale) {
            $translated = $this->issueTranslations($locale);

            foreach (array_keys($english) as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $translated,
                    sprintf('[%s] is missing from %s, and renders as the code.', $code, $locale),
                );
            }

            // Present is not the same as translated. A locale file copied from
            // English passes a key check and fails a reader.
            $this->assertNotSame(
                $english,
                $translated,
                sprintf('The %s issue translations are byte-identical to English.', $locale),
            );
        }
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Somebody who may write to the catalogue.
     */
    private function operator(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'operator@example.test'],
            ['name' => 'Operator', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) && ! is_link($full) ? $this->rmrf($full) : unlink($full);
        }

        rmdir($path);
    }

    /**
     * @return array<string, string>
     */
    private function issueTranslations(string $locale): array
    {
        /** @var array<string, mixed> $lines */
        $lines = require base_path('lang/'.$locale.'/ui.php');

        /** @var array<string, string> $issues */
        $issues = $lines['issue'] ?? [];

        return $issues;
    }

    /**
     * Run every queued ingestion item to completion, as a worker would.
     */
    private function drainQueue(): void
    {
        $processor = $this->app->make(ProcessIngestionItem::class);
        $finalizer = $this->app->make(FinalizeIngestionBatch::class);

        foreach (IngestionItem::query()->whereNotNull('active_marker')->get() as $item) {
            $processor->handle($item);
        }

        foreach (IngestionBatch::query()->get() as $batch) {
            $finalizer->handle($batch);
        }

        // Analysis settles each candidate. On a host with no FFmpeg — which is
        // the deployment target, and what the suite runs as — an absent
        // analyser produces READY rather than WAITING_CAPABILITY, because a
        // platform where no candidate is ever promotable is one that does not
        // work on shared hosting. The walk depends on that being true.
        $settler = $this->app->make(SettleCandidateAfterAnalysis::class);

        foreach (TrackCandidate::query()->get() as $candidate) {
            $settler->handle($candidate);
        }
    }

    private function cover(): Asset
    {
        $bytes = 'artwork bytes';

        return Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/cover-'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]);
    }

    /**
     * A release that would be delivered if the distributor cooperated.
     */
    private function deliverableRelease(): Release
    {
        $builder = $this->app->make(ReleaseBuilder::class);
        $release = $builder->createDraft('Night Bus');

        $track = Track::factory()->create([
            'title' => 'Night Bus',
            'status' => TrackStatus::Ready,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
        ]);

        $track->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Isrc,
            'value' => 'GBAAA2600001',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $release = $builder->addTrack($release, $track);

        $builder->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(['name' => 'The Label']), 'role' => ReleaseArtistRole::Primary],
        ]);

        $builder->setCover($release->refresh(), $this->cover());

        $release = $builder->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);

        $release->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Upc,
            'value' => '088500000001',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $release->refresh()->markReady();

        return $release->refresh();
    }
}
