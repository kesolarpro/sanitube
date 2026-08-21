<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributionIdempotencyKey;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Services\ValidateDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use Tests\TestCase;

/**
 * Handing a release to a distributor.
 *
 * This is the one irreversible act in SaniTube. Everything else — importing,
 * analysing, promoting, generating, assembling — happens inside the platform
 * and can be undone. A submitted release is in somebody else's system, on its
 * way to stores, and a duplicate submission is what gets a label's whole
 * catalogue flagged rather than one release.
 *
 * Almost every test here is about not doing it twice.
 */
final class DistributionTest extends TestCase
{
    use RefreshDatabase;

    private FakeDistributor $distributor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['distribution.default' => 'fake']);
        $this->distributor = new FakeDistributor('fake', sandbox: false);
        $this->app->forgetInstance(DistributorManager::class);
        $this->app->make(DistributorManager::class)->register('fake', $this->distributor);
    }

    // ------------------------------------------------- without a distributor

    #[Test]
    public function an_installation_with_no_distributor_refuses_clearly(): void
    {
        // The default state of this platform. Too Lost arrives in DIST-002.
        config(['distribution.default' => DistributorManager::DISABLED]);
        $this->app->forgetInstance(DistributorManager::class);

        try {
            $this->submitter()->handle($this->readyRelease());
            $this->fail('Submission must refuse without a distributor.');
        } catch (DistributionException $exception) {
            $this->assertSame('DISTRIBUTOR_UNAVAILABLE', $exception->reason);
        }
    }

    #[Test]
    public function the_disabled_distributor_is_called_none(): void
    {
        foreach ([null, '', '   ', 'null', 'NULL'] as $configured) {
            $this->assertSame(DistributorManager::DISABLED, DistributorManager::normaliseName($configured));
        }

        $this->assertSame('toolost', DistributorManager::normaliseName('toolost'));
    }

    #[Test]
    public function the_null_distributor_never_reports_a_release_as_acceptable(): void
    {
        // An empty error list would read as approval from a distributor that
        // does not exist.
        config(['distribution.default' => DistributorManager::DISABLED]);
        $this->app->forgetInstance(DistributorManager::class);

        $verdict = $this->validator()->handle(
            $this->readyRelease(),
            $this->app->make(DistributorManager::class)->default(),
        );

        $this->assertFalse($verdict->isValid());
    }

    // ----------------------------------------------------------- validation

    #[Test]
    public function a_draft_release_cannot_be_delivered(): void
    {
        // Handing over a package still being assembled is how a half-built
        // release reaches stores.
        $release = $this->completeDraft();

        try {
            $this->submitter()->handle($release);
            $this->fail('A draft must not be deliverable.');
        } catch (DistributionException $exception) {
            $this->assertSame('RELEASE_NOT_READY', $exception->reason);
        }
    }

    #[Test]
    public function a_track_with_no_isrc_blocks_delivery_and_no_isrc_is_minted(): void
    {
        // The rule that matters most here. An ISRC assigned automatically to a
        // recording that was already distributed elsewhere is the mistake that
        // cannot be taken back once a store has seen it.
        $release = $this->readyRelease(withIsrc: false);

        $verdict = $this->validator()->handle($release, $this->distributor);

        $this->assertFalse($verdict->isValid());
        $this->assertTrue($verdict->has('TRACK_NO_ISRC'));

        // And nothing was created to fix it.
        $this->assertSame(
            0,
            $release->tracks()->first()?->externalIdentifiers()
                ->where('type', ExternalIdentifierType::Isrc->value)->count(),
        );
    }

    #[Test]
    public function a_release_with_no_upc_blocks_delivery(): void
    {
        $release = $this->readyRelease(withUpc: false);

        $verdict = $this->validator()->handle($release, $this->distributor);

        $this->assertFalse($verdict->isValid());
        $this->assertTrue($verdict->has('NO_PRODUCT_IDENTIFIER'));
    }

    #[Test]
    public function a_sandbox_distributor_is_warned_about_rather_than_blocked(): void
    {
        // A sandbox delivery is a legitimate rehearsal. Submitting a real
        // release to one is a mistake that must be visible, not prevented.
        $sandbox = new FakeDistributor('sandbox', sandbox: true);
        $this->app->make(DistributorManager::class)->register('sandbox', $sandbox);

        $verdict = $this->validator()->handle($this->readyRelease(), $sandbox);

        $this->assertTrue($verdict->isValid(), implode('; ', $verdict->messages()));
        $this->assertTrue($verdict->has('DISTRIBUTOR_SANDBOX'));
    }

    #[Test]
    public function a_distributor_that_refuses_the_package_stops_the_submission(): void
    {
        $this->distributor->rejecting(['Genre code not recognised.']);
        $release = $this->readyRelease();

        try {
            $this->submitter()->handle($release);
            $this->fail('A refused package must not be submitted.');
        } catch (DistributionException $exception) {
            $this->assertSame('DISTRIBUTOR_REJECTED', $exception->reason);
        }

        $this->assertSame(0, $this->distributor->submitCalls);
    }

    // ---------------------------------------------------------- idempotence

    #[Test]
    public function a_release_is_delivered_once_and_the_second_attempt_is_refused(): void
    {
        $release = $this->readyRelease();

        $delivery = $this->submitter()->handle($release);
        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);

        try {
            $this->submitter()->handle($release->refresh());
            $this->fail('A second submission must be refused.');
        } catch (DistributionException $exception) {
            $this->assertSame('ALREADY_SUBMITTED', $exception->reason);
        }

        $this->assertSame(1, $this->distributor->submitCalls);
        $this->assertSame(1, DistributionDelivery::query()->count());
    }

    #[Test]
    public function the_database_refuses_two_deliveries_of_one_release_to_one_distributor(): void
    {
        // The guard that holds whatever the code does.
        $release = $this->readyRelease();
        $this->submitter()->open($release);

        $this->expectException(UniqueConstraintViolationException::class);

        DistributionDelivery::query()->create([
            'release_id' => $release->id,
            'provider' => 'fake',
            'status' => DistributionDeliveryStatus::Draft,
            'idempotency_key' => str_repeat('a', 64),
        ]);
    }

    #[Test]
    public function the_idempotency_key_is_stable_across_attempts(): void
    {
        // Derived rather than random: a random key stored on the row is lost
        // if the row was never written, which is precisely the crash the key
        // exists to survive.
        $release = $this->readyRelease();

        $first = DistributionIdempotencyKey::for($release, 'fake');
        $second = DistributionIdempotencyKey::for($release->refresh(), 'fake');

        $this->assertSame($first, $second);
        $this->assertSame($first, $this->submitter()->open($release)->idempotency_key);

        // And it is scoped to the distributor: the same release to two
        // distributors is two deliveries, not one repeated.
        $this->assertNotSame($first, DistributionIdempotencyKey::for($release, 'other'));
    }

    #[Test]
    public function a_retry_after_an_outage_reuses_the_same_key_and_creates_one_delivery(): void
    {
        // The case idempotency exists for: SaniTube does not know whether the
        // package arrived.
        $release = $this->readyRelease();

        $this->distributor->down();
        $delivery = $this->submitter()->handle($release);

        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);
        $this->assertTrue($delivery->status->isSubmittable(), 'A failed delivery must be retryable.');

        $key = $delivery->idempotency_key;

        $this->distributor->recover();
        $retried = $this->submitter()->handle($release->refresh());

        $this->assertSame(DistributionDeliveryStatus::Submitted, $retried->status);
        $this->assertSame($key, $retried->idempotency_key);
        $this->assertSame(1, DistributionDelivery::query()->count());
    }

    #[Test]
    public function an_outage_leaves_the_delivery_failed_rather_than_stuck_submitting(): void
    {
        // A row permanently mid-flight is a row nobody retries and nobody
        // cleans up.
        $this->distributor->down();

        $delivery = $this->submitter()->handle($this->readyRelease());

        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);
        $this->assertNotNull($delivery->failure_reason);
    }

    // ------------------------------------------------------------- lifecycle

    #[Test]
    public function a_delivery_follows_the_distributor_from_submitted_to_live(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->submitter()->handle($release);

        $externalId = (string) $delivery->external_release_id;

        $this->distributor->advance($externalId, DeliveryStatus::Accepted);
        $delivery = $this->submitter()->sync($delivery);
        $this->assertSame(DistributionDeliveryStatus::Accepted, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);

        $this->distributor->advance($externalId, DeliveryStatus::Live);
        $delivery = $this->submitter()->sync($delivery);
        $this->assertSame(DistributionDeliveryStatus::Live, $delivery->status);
        $this->assertNotNull($delivery->live_at);
        $this->assertTrue($delivery->status->isPubliclyAvailable());
    }

    #[Test]
    public function a_transient_outage_during_sync_does_not_move_the_delivery(): void
    {
        // Inventing a status from a failed request is how a local record
        // starts disagreeing with reality.
        $delivery = $this->submitter()->handle($this->readyRelease());

        $this->distributor->down();
        $synced = $this->submitter()->sync($delivery);

        $this->assertSame(DistributionDeliveryStatus::Submitted, $synced->status);
    }

    /**
     * The three paths that write an attempt without going through `fail()`.
     *
     * `sync()`, `reconcile()` and `requestTakedown()` reach `record()`
     * directly with an exception's own words, so the scrub in `fail()` does
     * not cover them. A mutation proved it: removing the one in `record()`
     * changed nothing any test could see.
     *
     * `response_summary` is durable — read by the detail screen and carried
     * into every database backup.
     */
    #[Test]
    public function an_outage_during_sync_does_not_record_the_address_it_failed_at(): void
    {
        $delivery = $this->submitter()->handle($this->readyRelease());

        $this->distributor->down(
            'GET https://api.distributor.example/submissions/abc'
                .'?X-Amz-Signature=deadbeefcafe timed out',
        );

        $this->submitter()->sync($delivery);

        $summary = (string) $delivery->attempts()->latest('id')->firstOrFail()->response_summary;

        $this->assertStringNotContainsString('X-Amz-Signature', $summary);
        $this->assertStringNotContainsString('api.distributor.example', $summary);
        $this->assertStringContainsString('timed out', $summary, 'Scrubbed to nothing records nothing.');
    }

    #[Test]
    public function syncing_a_delivery_that_was_never_handed_over_asks_nothing(): void
    {
        $delivery = $this->submitter()->open($this->readyRelease());

        $this->assertSame(DistributionDeliveryStatus::Draft, $this->submitter()->sync($delivery)->status);
        $this->assertSame(0, $delivery->attempts()->count());
    }

    #[Test]
    public function a_live_release_can_be_taken_down(): void
    {
        $delivery = $this->submitter()->handle($this->readyRelease());
        $this->distributor->advance((string) $delivery->external_release_id, DeliveryStatus::Live);
        $delivery = $this->submitter()->sync($delivery);

        $delivery = $this->submitter()->requestTakedown($delivery);

        $this->assertSame(DistributionDeliveryStatus::TakedownRequested, $delivery->status);
    }

    #[Test]
    public function nothing_that_was_never_delivered_can_be_taken_down(): void
    {
        $delivery = $this->submitter()->open($this->readyRelease());

        try {
            $this->submitter()->requestTakedown($delivery);
            $this->fail('An undelivered release must not be takedownable.');
        } catch (DistributionException $exception) {
            $this->assertSame('NOT_TAKEDOWNABLE', $exception->reason);
        }
    }

    // -------------------------------------------------------------- attempts

    #[Test]
    public function every_conversation_with_the_distributor_is_recorded(): void
    {
        // "This release was rejected" and "we tried four times and the fourth
        // failed differently" are different facts, and only the second says
        // whether the problem is the metadata or the distributor.
        $release = $this->readyRelease();

        $this->distributor->down();
        $this->submitter()->handle($release);

        $this->distributor->recover();
        $delivery = $this->submitter()->handle($release->refresh());
        $this->submitter()->sync($delivery);

        $attempts = $delivery->attempts()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(3, $attempts->count());
        $this->assertSame(
            [true],
            array_values(array_unique($attempts->pluck('idempotency_key')->map(
                fn (string $key): bool => $key === $delivery->idempotency_key,
            )->all())),
            'Every attempt must carry the delivery key.',
        );
    }

    // --------------------------------------------------------------- helpers

    private function submitter(): SubmitDelivery
    {
        return $this->app->make(SubmitDelivery::class);
    }

    private function validator(): ValidateDelivery
    {
        return $this->app->make(ValidateDelivery::class);
    }

    private function builder(): ReleaseBuilder
    {
        return $this->app->make(ReleaseBuilder::class);
    }

    private function completeDraft(bool $withIsrc = true): Release
    {
        $release = $this->builder()->createDraft('Night Bus');

        // `ready()`, not a forced status. The factory's own comment says why:
        // writing TrackStatus::Ready directly produces a track the domain
        // considers impossible — READY with no master audio — and this fixture
        // did exactly that. Nothing noticed until DIST-007 gave the adapter a
        // ReleasePackage: packaging refuses TRACK_WITHOUT_MASTER, because there
        // is nothing to deliver for such a track. The old submission path
        // handed the aggregate over and never looked.
        $track = Track::factory()->ready()->create([
            'title' => 'Night Bus',
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
        ]);

        if ($withIsrc) {
            $track->externalIdentifiers()->create([
                'type' => ExternalIdentifierType::Isrc,
                'value' => 'FRZ859900001',
                'source' => ExternalIdentifierSource::Manual,
                'assigned_at' => now(),
            ]);
        }

        $release = $this->builder()->addTrack($release, $track);

        $this->builder()->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(), 'role' => ReleaseArtistRole::Primary],
        ]);

        $bytes = 'artwork bytes';

        $this->builder()->setCover($release->refresh(), Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]));

        return $this->builder()->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function readyRelease(bool $withIsrc = true, bool $withUpc = true): Release
    {
        $release = $this->completeDraft($withIsrc);

        if ($withUpc) {
            $release->externalIdentifiers()->create([
                'type' => ExternalIdentifierType::Upc,
                'value' => '0885000000001',
                'source' => ExternalIdentifierSource::Manual,
                'assigned_at' => now(),
            ]);
        }

        $release->markReady();

        return $release->refresh();
    }
}
