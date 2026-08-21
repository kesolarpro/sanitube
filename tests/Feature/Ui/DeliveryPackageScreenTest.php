<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
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
use SaniTube\Distribution\Export\DeliveryPackageKeys;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * DIST-005. Delivering by hand, from a browser.
 *
 * DIST-004 built the package and gave it a command. That is the right tool for
 * the initial catalogue and the wrong one for the deployment this platform
 * exists to support: **a label on a shared cPanel account has no shell**, and a
 * delivery path that requires SSH is a delivery path that installation does not
 * have. One of the three supported installations could reach READY and then had
 * nowhere to go.
 *
 * Three claims carry these tests:
 *
 *   - **Building is not submitting.** Two files, no delivery row, nothing
 *     irreversible — and no control anywhere on the screen that says a
 *     distributor received anything.
 *   - **The page never carries a link.** A signed URL is a bearer credential;
 *     each is asked for one master at a time, through the ordinary minting
 *     endpoint with its own policy, throttle and audit line.
 *   - **A MEMBER cannot.** Choosing what a distributor is handed is the same
 *     decision whether an API or a person carries it.
 */
final class DeliveryPackageScreenTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // ------------------------------------------------------------- the screen

    #[Test]
    public function the_screen_says_no_package_exists_until_one_is_built(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package")
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Distribution/Package')
                ->where('package.package', null)
                ->where('package.release_is_ready', true)
            );
    }

    #[Test]
    public function building_writes_the_package_and_the_screen_then_describes_it(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())
            ->post("/releases/{$release->uuid}/distribution/package")
            ->assertRedirect("/releases/{$release->uuid}/distribution/package")
            // UI-006: a write that worked says so.
            ->assertSessionHas('status', 'distribution.package_built');

        $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package")
            ->assertInertia(fn ($page) => $page
                ->where('package.package.track_count', 1)
                ->has('package.package.tracks', 1)
            );
    }

    /**
     * The whole point, stated once: nothing was handed to anybody.
     */
    #[Test]
    public function building_from_the_browser_delivers_nothing(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");
        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    /**
     * **No link reaches the page.**
     *
     * A signed URL is a bearer credential that expires. A screen that arrived
     * carrying one per track would have minted twelve for somebody who opened
     * it to read a filename — and each would sit in the browser's history and
     * in any proxy that saw the response.
     */
    #[Test]
    public function the_payload_carries_no_link_and_no_object_key(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $response = $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package");

        /** @var array{props: array<string, mixed>} $page */
        $page = $response->viewData('page');

        $payload = (string) json_encode($page['props']['package']);

        $this->assertStringNotContainsString('http://', $payload);
        $this->assertStringNotContainsString('https://', $payload);

        // Nor where the master lives. The asset's public uuid is enough for the
        // screen to ask the minting endpoint for a link.
        $this->assertStringNotContainsString('masters/', $payload);
        $this->assertStringNotContainsString('storage_key', $payload);
    }

    /**
     * A storage hiccup reads as "no package", not as a broken screen.
     *
     * The remedy is the same button either way, and a page that 500s because a
     * bucket was briefly unreachable is a page an operator cannot use to find
     * out what is wrong — at exactly the moment they are trying to.
     */
    #[Test]
    public function a_storage_failure_reads_as_no_package_rather_than_a_broken_screen(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $this->store->failWith('the bucket is unreachable');

        $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package")
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('package.package', null));
    }

    /**
     * What is worth checking before uploading reaches the screen.
     *
     * A warning nobody sees is a warning that was not given: the sheet goes to
     * a portal that registers whatever it finds, and a blank ISRC column is the
     * kind of thing an operator wants to know about *before* rather than after.
     */
    #[Test]
    public function the_warnings_reach_the_screen(): void
    {
        $release = $this->readyRelease(withIsrc: false);

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $response = $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package");

        /** @var array{props: array{package: array{package: array{warnings: list<string>}}}} $page */
        $page = $response->viewData('page');

        $this->assertNotSame([], $page['props']['package']['package']['warnings']);
        $this->assertStringContainsString(
            'ISRC',
            implode(' ', $page['props']['package']['package']['warnings']),
        );
    }

    // ------------------------------------------------------------ the refusal

    #[Test]
    public function a_draft_release_cannot_be_packaged_and_is_told_why(): void
    {
        $release = $this->completeDraft();

        $this->actingAs($this->operator())
            ->post("/releases/{$release->uuid}/distribution/package")
            ->assertSessionHasErrors(['distribution' => 'RELEASE_NOT_READY']);

        $this->assertSame([], $this->store->files('exports'));
    }

    /**
     * A refusal from the *packager* reaches the screen too.
     *
     * The two refusals come from different modules, and a controller that
     * caught only the one it was written beside would answer a real problem
     * with a 500.
     */
    #[Test]
    public function a_refusal_from_the_packager_reaches_the_screen(): void
    {
        $release = $this->readyRelease();

        // READY, and then the cover goes. Validation passed when it was marked
        // ready; packaging asks again, which is the point of asking again.
        $release->forceFill(['cover_asset_id' => null])->save();

        $this->actingAs($this->operator())
            ->post("/releases/{$release->uuid}/distribution/package")
            ->assertSessionHasErrors('distribution');
    }

    // ----------------------------------------------------------- the download

    #[Test]
    public function the_sheet_downloads_as_a_file(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $response = $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package/metadata");

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // The uuid, not the title. A filename built from user text is a
        // filename that can carry a quote, a newline and a second header.
        $response->assertHeader(
            'Content-Disposition',
            sprintf('attachment; filename="sanitube-%s-metadata.csv"', $release->uuid),
        );

        // A delivery sheet is the catalogue in a file. Nothing between here and
        // the operator may write it down.
        $response->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->assertStringContainsString('Night Bus', $response->streamedContent());
    }

    #[Test]
    public function downloading_a_sheet_that_was_never_built_is_a_refusal_rather_than_a_crash(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())
            ->get("/releases/{$release->uuid}/distribution/package/metadata")
            ->assertSessionHasErrors(['distribution' => 'PACKAGE_NOT_BUILT']);
    }

    // ------------------------------------------------------- authorisation

    #[Test]
    public function a_member_may_not_reach_any_of_it(): void
    {
        $release = $this->readyRelease();
        $member = $this->operator(UserRole::Member);

        $this->actingAs($member)->get("/releases/{$release->uuid}/distribution/package")->assertForbidden();
        $this->actingAs($member)->post("/releases/{$release->uuid}/distribution/package")->assertForbidden();
        $this->actingAs($member)
            ->get("/releases/{$release->uuid}/distribution/package/metadata")
            ->assertForbidden();

        $this->assertSame([], $this->store->files('exports'));
    }

    /**
     * And a package written by somebody else is still not readable by a member.
     */
    #[Test]
    public function a_member_cannot_download_a_package_that_already_exists(): void
    {
        $release = $this->readyRelease();

        $this->actingAs($this->operator())->post("/releases/{$release->uuid}/distribution/package");

        $this->assertTrue($this->store->exists(
            $this->app->make(DeliveryPackageKeys::class)->metadata($release),
        ));

        $this->actingAs($this->operator(UserRole::Member))
            ->get("/releases/{$release->uuid}/distribution/package/metadata")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- fixtures

    private function builder(): ReleaseBuilder
    {
        return $this->app->make(ReleaseBuilder::class);
    }

    private function completeDraft(bool $withIsrc = true): Release
    {
        $release = $this->builder()->createDraft('Night Bus');

        $master = Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'masters/'.bin2hex(random_bytes(4)).'/original.wav',
            'original_filename' => 'take-3.wav',
            'sha256' => hash('sha256', 'audio'),
            'byte_size' => 5,
            'mime_type' => 'audio/wav',
        ]);

        $track = Track::factory()->create([
            'title' => 'Night Bus',
            'status' => TrackStatus::Ready,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
            'master_asset_id' => $master->id,
            'duration_ms' => 214_000,
        ]);

        if ($withIsrc) {
            $track->externalIdentifiers()->create([
                'type' => ExternalIdentifierType::Isrc,
                'value' => 'FRZ859900001',
                'source' => ExternalIdentifierSource::Manual,
                'assigned_at' => now(),
                'active_marker' => 1,
            ]);
        }

        $track->artists()->attach(Artist::factory()->create(), ['role' => 'PRIMARY']);

        $release = $this->builder()->addTrack($release, $track);

        $this->builder()->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(), 'role' => ReleaseArtistRole::Primary],
        ]);

        $this->builder()->setCover($release->refresh(), Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', 'artwork'),
            'byte_size' => 7,
            'mime_type' => 'image/jpeg',
        ]));

        return $this->builder()->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function readyRelease(bool $withIsrc = true): Release
    {
        $release = $this->completeDraft($withIsrc);

        $release->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Upc,
            'value' => '0885000000001',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
            'active_marker' => 1,
        ]);

        $release->markReady();

        return $release->refresh();
    }

    private function operator(UserRole $role = UserRole::Owner): User
    {
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'operator-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
