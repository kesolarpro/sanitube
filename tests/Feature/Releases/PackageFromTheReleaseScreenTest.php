<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Artwork\Testing\FakeImageMeasurer;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * REL-004 — the package becomes something a person can prepare.
 *
 * REL-003 built `PackageRelease` and gave it no caller. It is the single
 * description of a release that leaves this platform, and until this ticket the
 * only way to see one was to write a test: nobody assembling a release could
 * find out what a store would receive until a store had received it.
 *
 * **The defect this closes is larger than a missing button.** The identifier
 * requirements — a UPC on the release, an ISRC on every track — lived inside
 * `ValidateDelivery`, which is only ever asked about a *destination*. On an
 * installation with no distributor configured, which is the shipped default and
 * the state every installation starts in, a release with no ISRC at all passed
 * validation, was marked READY, and said nothing. The first news would have
 * arrived from a store.
 *
 * So three answers are kept apart here, because a release can be blocked by any
 * one of them while the other two are fine: whether the package assembles,
 * whether the identifiers exist, and whether anywhere exists to send it.
 */
final class PackageFromTheReleaseScreenTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer(
            new ArtworkMeasurement(3000, 3000, 'image/jpeg', 900_000, channels: 3),
        ));
    }

    // --------------------------------------------------------------- access

    #[Test]
    public function preparing_a_package_is_behind_authentication(): void
    {
        $release = Release::factory()->create();

        $this->get('/releases/'.$release->uuid.'/package')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_read_only_member_cannot_prepare_a_package(): void
    {
        // The package carries contributors' *legal* names — the ones collecting
        // societies match on — which no other release screen shows, and it is
        // the description of what would be handed to a third party. The hidden
        // button is presentation; this is the check.
        $release = $this->validRelease();

        $this->actingAs($this->user(UserRole::Member))
            ->get('/releases/'.$release->uuid.'/package')
            ->assertForbidden();
    }

    // -------------------------------------------------------- what it shows

    #[Test]
    public function a_valid_release_prepares_and_the_tracklist_is_the_one_a_distributor_receives(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the release builder screen, from the
        // "Prepare the package" button. Before this ticket PackageRelease had
        // no production caller at all.
        $release = $this->validRelease();
        $second = Track::factory()->ready()->create(['title' => 'Second']);
        $release->tracks()->attach($second->id, ['disc_number' => 1, 'track_number' => 2]);

        $body = $this->prepare($release);

        $this->assertTrue($body['prepared']);
        $this->assertNull($body['refusal']);
        $this->assertSame($release->uuid, $body['package']['uuid']);
        $this->assertCount(2, $body['package']['tracks']);
        $this->assertSame([1, 2], array_column($body['package']['tracks'], 'track_number'));
    }

    #[Test]
    public function an_invalid_release_is_refused_by_code_and_carries_no_package(): void
    {
        // A screen that showed a partial package for an invalid release would
        // be showing something no distributor would ever be sent.
        $release = Release::factory()->create(['cover_asset_id' => null, 'release_date' => null]);

        $body = $this->prepare($release);

        $this->assertFalse($body['prepared']);
        $this->assertSame('RELEASE_NOT_VALID', $body['refusal']);
        $this->assertNull($body['package']);
    }

    #[Test]
    public function a_track_with_no_master_is_named_by_uuid_rather_than_silently_dropped(): void
    {
        // Dropping it would deliver a release missing a track, and nobody would
        // find out until a store published a two-track single as one.
        $release = $this->validRelease();

        $orphan = Track::factory()->ready()->create();
        $release->tracks()->attach($orphan->id, ['disc_number' => 1, 'track_number' => 2]);
        $orphan->forceFill(['master_asset_id' => null])->save();

        $body = $this->prepare($release->refresh());

        $this->assertSame('TRACK_WITHOUT_MASTER', $body['refusal']);
        $this->assertSame([$orphan->uuid], $body['refusal_context']);
    }

    #[Test]
    public function the_server_never_sends_a_sentence(): void
    {
        // REL-002. `ReleasePackagingException::getMessage()` is written for a
        // developer reading a log and names internal concepts; a screen that
        // rendered it would make it a contract by accident.
        $release = Release::factory()->create(['cover_asset_id' => null, 'release_date' => null]);

        $body = $this->prepare($release);

        foreach (['message', 'error', 'detail', 'exception'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
    }

    // ------------------------------------------------- what blocks delivery

    #[Test]
    public function the_identifiers_every_delivery_needs_are_reported_beside_a_package_that_assembled(): void
    {
        // The defect this ticket exists for. Packaging asks REL-001's
        // validator, which says nothing about identifiers; delivery also asks
        // for a UPC and an ISRC per track. Reporting only the first would call
        // a release ready that no store can identify.
        $release = $this->validRelease();

        $body = $this->prepare($release);

        $this->assertTrue($body['prepared'], 'The package itself is fine — that is the point.');

        $codes = array_column($body['blocking_identifiers'], 'code');

        $this->assertContains('NO_PRODUCT_IDENTIFIER', $codes);
        $this->assertContains('TRACK_NO_ISRC', $codes);
    }

    #[Test]
    public function assigning_the_identifiers_clears_the_list(): void
    {
        $release = $this->validRelease();

        $this->assign($release, ExternalIdentifierType::Upc, '088515001233');

        // Numbered from a counter, never from `$track->id`. An ISRC is exactly
        // twelve characters, and building one by interpolating a primary key
        // produces a thirteenth as soon as ids reach double figures — which
        // never happens on SQLite, where RefreshDatabase resets the sequence,
        // and always happens on MySQL and MariaDB, where it does not. A test
        // that passes on one engine for that reason is testing the engine.
        $designation = 0;

        foreach ($release->tracks()->get() as $track) {
            $this->assign(
                $track,
                ExternalIdentifierType::Isrc,
                'FRZ0324'.sprintf('%05d', ++$designation),
            );
        }

        $this->assertSame([], $this->prepare($release)['blocking_identifiers']);
    }

    #[Test]
    public function a_revoked_product_identifier_does_not_identify_anything(): void
    {
        // A revoked UPC is still a row, deliberately, so the history is
        // readable. Counting one would tell a label its release is identified
        // when a store would receive nothing to identify it by.
        $release = $this->validRelease();

        $upc = $this->assign($release, ExternalIdentifierType::Upc, '088515001233');

        $this->assertNotContains(
            'NO_PRODUCT_IDENTIFIER',
            array_column($this->prepare($release)['blocking_identifiers'], 'code'),
        );

        ($this->app->make(RevokeExternalIdentifier::class))($upc, 'Withdrawn by the test.');

        $this->assertContains(
            'NO_PRODUCT_IDENTIFIER',
            array_column($this->prepare($release)['blocking_identifiers'], 'code'),
        );
    }

    #[Test]
    public function an_installation_with_no_distributor_says_so_rather_than_leaving_it_to_be_discovered(): void
    {
        // The one blocker no amount of editing the release will clear. Without
        // it, a label fixes a perfect package over and over wondering why the
        // destination screen is empty.
        $release = $this->validRelease();

        $this->configured(['none' => ['driver' => 'none']]);

        $this->assertSame(0, $this->prepare($release)['destinations_configured']);

        // `none` is the name of *not having* a distributor, so it is not one:
        // two entries, one destination.
        $this->configured([
            'none' => ['driver' => 'none'],
            'fake' => ['driver' => 'fake', 'sandbox' => true],
        ]);

        $this->assertSame(1, $this->prepare($release)['destinations_configured']);
    }

    // ------------------------------------------------------ what it must not do

    #[Test]
    public function preparing_leaves_no_record_behind(): void
    {
        // A "let me look" that created a DRAFT delivery would become a record
        // somebody later reads as an intention. It is a GET for the same
        // reason the distributor preflight is.
        $release = $this->validRelease();

        $this->prepare($release);

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    #[Test]
    public function no_location_and_no_secret_travels_to_the_browser(): void
    {
        // REL-003's guarantee, re-asserted at the boundary that actually leaves
        // the server. Walked whole rather than checked field by field, because
        // the fields somebody remembers to check are not the ones that leak.
        $release = $this->validRelease();

        $body = $this->prepare($release);

        $package = json_encode($body['package']);
        $this->assertIsString($package);

        // `path` is in this list and is why it is asserted against the package
        // rather than the whole payload: a ValidationIssue carries a `path`
        // too, and it means a field on a form — `identifiers`, `tracks.<uuid>`
        // — not a place on a disk. Two different words spelt the same way.
        foreach (['disk', 'path', 'bucket', 'secret', 'token', 'http://', 'memory'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($package));
        }

        $whole = json_encode($body);
        $this->assertIsString($whole);

        foreach (['disk', 'bucket', 'secret', 'token', 'http://', 'memory'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($whole));
        }
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function prepare(Release $release): array
    {
        $response = $this->actingAs($this->user())
            ->getJson('/releases/'.$release->uuid.'/package')
            ->assertOk();

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * @param  array<string, array<string, mixed>>  $distributors
     */
    private function configured(array $distributors): void
    {
        // The manager is constructed with the configuration array and keeps it,
        // so writing `config()` after it has been resolved changes nothing —
        // and a test that did only that would quietly assert against whatever
        // the container already held.
        config(['distribution.distributors' => $distributors]);

        $this->app->forgetInstance(DistributorManager::class);
    }

    private function assign(object $entity, ExternalIdentifierType $type, string $value): ExternalIdentifier
    {
        // Through the platform's own service, not hand-built. A row inserted
        // directly can be a shape the platform would never produce.
        return ($this->app->make(AssignExternalIdentifier::class))($entity, $type, $value);
    }

    private function validRelease(): Release
    {
        $cover = Asset::factory()->artwork()->verified()->create(['disk' => 'memory']);
        $this->store->put($cover->path, 'cover-bytes');
        $this->app->make(MeasureArtwork::class)->handle($cover);

        $release = Release::factory()->create([
            'cover_asset_id' => $cover->id,
            'release_date' => now()->addMonth()->toDateString(),
        ]);

        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value,
            'position' => 0,
        ]);

        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1,
            'track_number' => 1,
            'is_focus_track' => true,
        ]);

        return $release->refresh();
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
