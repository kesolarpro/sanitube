<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Ui\Assets\AssetPreviewDecision;
use SaniTube\Ui\Assets\MintAssetPreviewUrl;
use SaniTube\Ui\Queries\AssetDetailQuery;
use SaniTube\Ui\Queries\AssetIndexQuery;
use Tests\TestCase;

/**
 * Assets, and the boundary that protects the masters.
 *
 * A signed URL is a bearer credential. The assertions that matter most here are
 * about when one is *not* created: never on a page load, never for an
 * unverified master, never for a role that may browse but not hold the
 * material, and never as a permanent or public URL when the provider cannot
 * issue an expiring one.
 */
final class CatalogAssetsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A provider that can actually issue an expiring URL.
     *
     * The default `local` provider cannot, in this environment or on a plain
     * filesystem disk anywhere. Leaving it in place would make every positive
     * assertion here pass for the wrong reason — the preview would be refused,
     * the refusal test would go green, and nothing would be testing the minting
     * path at all. Registering it under the *default* name because the manager
     * already read the config and will not read it again.
     */
    private function useProviderThatSignsUrls(bool $canSign = true): void
    {
        // Registered under the name the fixtures record on the asset, because
        // that — not the default — is what the policy resolves.
        $this->registerProvider('local', canSign: $canSign, baseUrl: 'https://storage.example.test');
    }

    /**
     * A provider registered under a name of our choosing.
     *
     * The base URL differs per provider so a signed URL can be traced back to
     * the backend that produced it. That is what makes "signs B, not A"
     * assertable at all.
     */
    private function registerProvider(string $name, bool $canSign = true, string $baseUrl = 'https://storage.example.test'): void
    {
        app(StorageManager::class)->register(
            $name,
            new InMemoryStorageProvider(name: $name, baseUrl: $baseUrl, temporaryUrls: $canSign),
        );
    }

    #[Test]
    public function the_asset_list_is_behind_authentication(): void
    {
        $this->get('/catalog/assets')->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_assets_and_is_reachable_only_by_uuid(): void
    {
        $asset = $this->asset();
        $user = $this->user();

        $this->actingAs($user)->get('/catalog/assets')->assertOk();
        $this->actingAs($user)->get('/catalog/assets/'.$asset->uuid)->assertOk();
        $this->actingAs($user)->get('/catalog/assets/'.$asset->getKey())->assertNotFound();
    }

    // ------------------------------------------------------------ the trash

    #[Test]
    public function a_trashed_asset_drops_out_of_the_list_but_stays_reachable(): void
    {
        // Setting something aside that then goes on appearing in every listing
        // is not setting it aside. But an asset nobody can find is one nobody
        // can restore, so the row has to remain addressable.
        $kept = $this->asset();
        $trashed = $this->asset();
        $trashed->forceFill(['trashed_at' => now(), 'trash_reason' => 'DUPLICATE'])->save();

        $query = $this->app->make(AssetIndexQuery::class);

        $listed = array_column($query->paginate()['rows'], 'uuid');
        $this->assertContains($kept->uuid, $listed);
        $this->assertNotContains($trashed->uuid, $listed);

        $this->actingAs($this->user())->get('/catalog/assets/'.$trashed->uuid)->assertOk();
    }

    #[Test]
    public function the_screen_asks_for_the_trash_the_same_way_the_query_understands_it(): void
    {
        // DUP-001. The read model has supported `trashed` from the start and
        // nothing on the screen ever sent it, so the only way to see the trash
        // was to type the query string by hand. A filter the interface cannot
        // reach is a filter that does not exist.
        $trashed = $this->asset();
        $trashed->forceFill(['trashed_at' => now(), 'trash_reason' => 'WRONG_UPLOAD'])->save();

        $response = $this->actingAs($this->user())->get('/catalog/assets?trashed=only');

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        // Echoed back, because the control has to come back showing what was
        // asked for rather than resetting itself on every page load.
        $this->assertSame('only', $props['filters']['trashed']);
        $this->assertSame([$trashed->uuid], array_column($props['page']['rows'], 'uuid'));
    }

    #[Test]
    public function a_trashed_row_carries_what_a_restore_control_needs(): void
    {
        $trashed = $this->asset();
        $trashed->forceFill(['trashed_at' => now(), 'trash_reason' => 'UNUSABLE_AUDIO'])->save();

        $rows = $this->app->make(AssetIndexQuery::class)->paginate(['trashed' => 'only'])['rows'];

        // An asset set aside for a reason other than a duplicate finding had
        // no restore control anywhere: the only one in the interface lived on
        // the duplicates page, beside a finding this asset does not have.
        $this->assertTrue($rows[0]['is_trashed']);
        $this->assertSame('UNUSABLE_AUDIO', $rows[0]['trash_reason']);
        $this->assertNotNull($rows[0]['trashed_at']);
    }

    #[Test]
    public function the_trash_can_be_listed_on_its_own(): void
    {
        $kept = $this->asset();
        $trashed = $this->asset();
        $trashed->forceFill(['trashed_at' => now(), 'trash_reason' => 'DUPLICATE'])->save();

        $rows = $this->app->make(AssetIndexQuery::class)->paginate(['trashed' => 'only'])['rows'];

        $this->assertSame([$trashed->uuid], array_column($rows, 'uuid'));
        $this->assertTrue($rows[0]['is_trashed']);
        $this->assertSame('DUPLICATE', $rows[0]['trash_reason']);

        $both = array_column($this->app->make(AssetIndexQuery::class)->paginate(['trashed' => 'all'])['rows'], 'uuid');
        $this->assertContains($kept->uuid, $both);
        $this->assertContains($trashed->uuid, $both);
    }

    // ------------------------------------------------------ nothing leaks

    #[Test]
    public function no_payload_reveals_where_an_asset_lives(): void
    {
        // The list and the detail both. A storage layout published in page
        // source is a storage layout that can no longer be changed.
        $asset = $this->asset();
        $viewer = $this->user();

        $payloads = [
            (string) json_encode(app(AssetIndexQuery::class)->paginate()),
            (string) json_encode(app(AssetDetailQuery::class)->forAsset($asset, $viewer)),
        ];

        foreach ($payloads as $encoded) {
            foreach (['"disk"', '"path"', '"bucket"', '"original_filename"', '"failure_message"'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded, sprintf('%s reached the browser.', $forbidden));
            }

            foreach (['"id"', '"asset_id"', '"parent_asset_id"', '"duplicate_of_asset_id"'] as $key) {
                $this->assertStringNotContainsString($key, $encoded, sprintf('%s reached the browser.', $key));
            }

            // And the value of the path, not just its key name.
            $this->assertStringNotContainsString($asset->path, $encoded);
        }
    }

    // ---------------------------------------- no signed URL on a page load

    #[Test]
    public function the_detail_payload_never_carries_a_signed_url(): void
    {
        // The assertion this whole ticket exists for. Rendering a detail page
        // must not create a credential the reader did not ask for.
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $detail = app(AssetDetailQuery::class)->forAsset($asset, $this->user());
        $encoded = (string) json_encode($detail);

        $this->assertArrayHasKey('preview', $detail);
        $this->assertArrayNotHasKey('url', $detail['preview'], 'A URL rode along with the detail payload.');
        $this->assertStringNotContainsString('http', $encoded, 'Something URL-shaped reached the detail payload.');

        // What it carries instead: whether a preview *could* be minted.
        $this->assertTrue($detail['preview']['available']);
        $this->assertNull($detail['preview']['reason']);
    }

    #[Test]
    public function opening_the_detail_page_mints_nothing(): void
    {
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $body = $this->actingAs($this->user())->get('/catalog/assets/'.$asset->uuid)->assertOk()->content();

        // A signed URL from the filesystem provider carries a signature query
        // parameter. None of that may appear in a rendered detail page.
        foreach (['signature=', 'X-Amz-Signature', 'expires='] as $marker) {
            $this->assertStringNotContainsStringIgnoringCase($marker, $body);
        }
    }

    // ------------------------------------------------------ minting on request

    #[Test]
    public function an_authorised_person_can_mint_a_preview_for_a_verified_master(): void
    {
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::AudioMaster, AssetStatus::Verified);

        $response = $this->actingAs($this->user(UserRole::Admin))
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertOk();

        $payload = $response->json();

        $this->assertIsString($payload['url']);
        $this->assertIsString($payload['expires_at']);

        // The expiry is real, and inside the allowed window.
        $seconds = strtotime((string) $payload['expires_at']) - time();
        $this->assertGreaterThan(280, $seconds);
        $this->assertLessThanOrEqual(900, $seconds);
    }

    #[Test]
    public function an_unverified_master_is_never_previewable(): void
    {
        // Playing an unverified master hands out bytes nobody has confirmed are
        // the bytes we meant.
        $asset = $this->asset(AssetKind::AudioMaster, AssetStatus::Stored);

        $this->actingAs($this->user(UserRole::Admin))
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::NotVerified->value]);
    }

    #[Test]
    public function a_member_may_see_that_a_master_exists_but_not_hear_it(): void
    {
        $asset = $this->asset(AssetKind::AudioMaster, AssetStatus::Verified);
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/catalog/assets/'.$asset->uuid)->assertOk();

        $this->actingAs($member)
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::NotPermitted->value]);
    }

    #[Test]
    public function a_document_is_not_preview_material(): void
    {
        // An allow-list, so a kind nobody has thought about is refused rather
        // than served.
        $asset = $this->asset(AssetKind::LicenseDocument, AssetStatus::Verified);

        $this->actingAs($this->user(UserRole::Owner))
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::NotPreviewable->value]);
    }

    #[Test]
    public function a_provider_without_temporary_urls_gets_preview_unavailable_not_a_public_object(): void
    {
        // The failure mode this rule exists to prevent: falling back to a
        // permanent or public URL because the expiring one was unavailable.
        $this->useProviderThatSignsUrls(canSign: false);

        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $this->actingAs($this->user(UserRole::Owner))
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::ProviderCannotIssueTemporaryUrls->value]);
    }

    #[Test]
    public function a_guest_cannot_mint_a_preview(): void
    {
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $this->post('/catalog/assets/'.$asset->uuid.'/preview')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_ttl_is_clamped_rather_than_trusted(): void
    {
        // A misconfigured environment must not be able to mint day-long
        // credentials, and nothing about the screen would look wrong if it did.
        config(['assets.preview.ttl_seconds' => 86400]);
        $this->assertSame(900, app(MintAssetPreviewUrl::class)->ttlSeconds());

        config(['assets.preview.ttl_seconds' => 5]);
        $this->assertSame(300, app(MintAssetPreviewUrl::class)->ttlSeconds());

        config(['assets.preview.ttl_seconds' => 600]);
        $this->assertSame(600, app(MintAssetPreviewUrl::class)->ttlSeconds());
    }

    #[Test]
    public function a_minted_url_is_never_persisted(): void
    {
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::AudioMaster, AssetStatus::Verified);

        $url = (string) $this->actingAs($this->user(UserRole::Admin))
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->json('url');

        $this->assertNotSame('', $url);

        // Nothing about the asset row changed, and no column holds the URL.
        $stored = (string) json_encode($asset->fresh()?->getAttributes());
        $this->assertStringNotContainsString('signature', $stored);
        $this->assertStringNotContainsString($url, $stored);
    }

    // ------------------------------------------- storage provenance is canonical

    #[Test]
    public function an_asset_is_signed_by_the_provider_it_records_not_the_default(): void
    {
        // The defect this correction exists for. An asset's `disk` is written
        // when its bytes are stored and is immutable from that moment; the
        // default provider is a configuration value that can change tomorrow.
        // Resolving the default would sign against a backend that does not hold
        // these bytes.
        $this->registerProvider('provider-a', baseUrl: 'https://a.example.test');
        $this->registerProvider('provider-b', baseUrl: 'https://b.example.test');
        config(['storage.default' => 'provider-a']);

        $asset = Asset::factory()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'verified_at' => now(),
            'disk' => 'provider-b',
        ]);

        $url = (string) $this->actingAs($this->user())
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertOk()
            ->json('url');

        $this->assertStringStartsWith('https://b.example.test', $url, 'The asset was signed by the wrong backend.');
        $this->assertStringNotContainsString('a.example.test', $url);
    }

    #[Test]
    public function changing_the_default_provider_moves_no_historical_asset(): void
    {
        $this->registerProvider('provider-a', baseUrl: 'https://a.example.test');
        $this->registerProvider('provider-b', baseUrl: 'https://b.example.test');

        $asset = Asset::factory()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'verified_at' => now(),
            'disk' => 'provider-a',
        ]);

        // The installation migrates to a new backend for *new* material.
        config(['storage.default' => 'provider-b']);

        $url = (string) $this->actingAs($this->user())
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertOk()
            ->json('url');

        $this->assertStringStartsWith('https://a.example.test', $url, 'A historical asset moved when the default changed.');
    }

    #[Test]
    public function an_identical_path_on_two_backends_signs_only_the_recorded_one(): void
    {
        // The failure this prevents is not a broken link — it is a working link
        // to somebody else's object that happens to share a path.
        $this->registerProvider('provider-a', baseUrl: 'https://a.example.test');
        $this->registerProvider('provider-b', baseUrl: 'https://b.example.test');
        config(['storage.default' => 'provider-a']);

        $shared = 'masters/2026/shared-path.wav';
        $user = $this->user();

        foreach (['provider-a' => 'a.example.test', 'provider-b' => 'b.example.test'] as $disk => $host) {
            $asset = Asset::factory()->create([
                'kind' => AssetKind::Artwork,
                'status' => AssetStatus::Verified,
                'verified_at' => now(),
                'disk' => $disk,
                'path' => $shared,
            ]);

            $url = (string) $this->actingAs($user)
                ->post('/catalog/assets/'.$asset->uuid.'/preview')
                ->assertOk()
                ->json('url');

            $this->assertStringStartsWith('https://'.$host, $url, sprintf('%s was signed by the wrong backend.', $disk));
        }
    }

    #[Test]
    public function an_asset_on_an_unknown_provider_is_refused_rather_than_falling_back(): void
    {
        // A provider removed from configuration. The tempting behaviour — sign
        // with the default — is exactly what must not happen.
        $this->registerProvider('provider-a', baseUrl: 'https://a.example.test');
        config(['storage.default' => 'provider-a']);

        $asset = Asset::factory()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'verified_at' => now(),
            'disk' => 'a-provider-that-no-longer-exists',
        ]);

        $this->actingAs($this->user())
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::ProviderCannotIssueTemporaryUrls->value]);
    }

    #[Test]
    public function an_asset_on_a_backend_that_cannot_sign_is_refused_even_when_the_default_can(): void
    {
        $this->registerProvider('provider-a', canSign: true, baseUrl: 'https://a.example.test');
        $this->registerProvider('provider-b', canSign: false, baseUrl: 'https://b.example.test');
        config(['storage.default' => 'provider-a']);

        $asset = Asset::factory()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'verified_at' => now(),
            'disk' => 'provider-b',
        ]);

        $this->actingAs($this->user())
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden()
            ->assertJson(['reason' => AssetPreviewDecision::ProviderCannotIssueTemporaryUrls->value]);
    }

    // -------------------------------------------------- the credential's edges

    #[Test]
    public function the_preview_response_may_never_be_written_down(): void
    {
        // A credential in a shared proxy cache, or a browser's disk cache,
        // outlives the tab that asked for it. `no-store` is the only directive
        // that forbids writing it at all.
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $response = $this->actingAs($this->user())->post('/catalog/assets/'.$asset->uuid.'/preview')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    #[Test]
    public function a_refusal_is_uncacheable_and_says_nothing_about_storage(): void
    {
        $asset = $this->asset(AssetKind::LicenseDocument, AssetStatus::Verified);

        $response = $this->actingAs($this->user())
            ->post('/catalog/assets/'.$asset->uuid.'/preview')
            ->assertForbidden();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // A cached "no" would survive the role or the status changing.
        $body = $response->content();
        $this->assertStringNotContainsString($asset->path, $body);
        $this->assertStringNotContainsString($asset->disk, $body);
    }

    #[Test]
    public function minting_is_rate_limited(): void
    {
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);
        $user = $this->user();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->actingAs($user)->post('/catalog/assets/'.$asset->uuid.'/preview')->assertOk();
        }

        $this->actingAs($user)->post('/catalog/assets/'.$asset->uuid.'/preview')->assertStatus(429);
    }

    #[Test]
    public function one_persons_exhausted_quota_is_not_anybody_elses(): void
    {
        // Keyed on the user, not the address: an office behind one IP must not
        // share a budget, and one compromised session must not spend everyone's.
        $this->useProviderThatSignsUrls();
        $asset = $this->asset(AssetKind::Artwork, AssetStatus::Verified);

        $heavy = $this->user(UserRole::Owner, 'heavy@example.test');
        $other = $this->user(UserRole::Owner, 'other@example.test');

        for ($attempt = 0; $attempt < 61; $attempt++) {
            $this->actingAs($heavy)->post('/catalog/assets/'.$asset->uuid.'/preview');
        }

        $this->actingAs($heavy)->post('/catalog/assets/'.$asset->uuid.'/preview')->assertStatus(429);
        $this->actingAs($other)->post('/catalog/assets/'.$asset->uuid.'/preview')->assertOk();
    }

    #[Test]
    public function ordinary_catalogue_reads_are_not_throttled_by_the_preview_budget(): void
    {
        $asset = $this->asset();
        $user = $this->user();

        for ($attempt = 0; $attempt < 70; $attempt++) {
            $this->actingAs($user)->get('/catalog/assets/'.$asset->uuid)->assertOk();
        }
    }

    // -------------------------------------------------------------- filtering

    #[Test]
    public function an_invalid_kind_is_refused_with_422(): void
    {
        $this->actingAs($this->user())->get('/catalog/assets?kind=HOLOGRAM')->assertStatus(422);
    }

    #[Test]
    public function a_malformed_cursor_is_refused_rather_than_crashing(): void
    {
        $this->actingAs($this->user())
            ->get('/catalog/assets?cursor='.urlencode(base64_encode('{"id":5}')))
            ->assertStatus(422);
    }

    #[Test]
    public function the_cursor_carries_no_internal_key_and_pages_do_not_repeat(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->asset();
        }

        $seen = [];
        $cursor = null;

        do {
            $page = app(AssetIndexQuery::class)->paginate([], $cursor, 2);

            if ($cursor === null) {
                $decoded = (string) base64_decode((string) $page['next_cursor'], true);
                $this->assertStringNotContainsString('"id"', $decoded);
                $this->assertStringNotContainsString('path', $decoded, 'The cursor published the storage path.');
            }

            foreach (array_column($page['rows'], 'uuid') as $uuid) {
                $this->assertNotContains($uuid, $seen, 'An asset appeared on two pages.');
                $seen[] = $uuid;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
    }

    // --------------------------------------------------------------- helpers

    private function asset(AssetKind $kind = AssetKind::Artwork, AssetStatus $status = AssetStatus::Verified): Asset
    {
        return Asset::factory()->create([
            'kind' => $kind,
            'status' => $status,
            'verified_at' => $status === AssetStatus::Verified ? now() : null,
        ]);
    }

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
