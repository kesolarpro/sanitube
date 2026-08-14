<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

final class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'catalogue-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanitube.api.internal_token' => self::TOKEN]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<JsonResponse>
     */
    private function get_(string $uri, array $query = []): TestResponse
    {
        return $this->withHeader('X-SaniTube-Api-Token', self::TOKEN)
            ->getJson($uri.($query === [] ? '' : '?'.http_build_query($query)));
    }

    // Access control

    #[Test]
    public function the_catalogue_api_does_not_exist_until_a_token_is_configured(): void
    {
        // A fresh install must not serve its catalogue to anyone who finds the
        // URL, and must not even admit the routes exist.
        config(['sanitube.api.internal_token' => null]);

        foreach (['artists', 'compositions', 'tracks', 'releases'] as $resource) {
            $this->getJson('api/v1/'.$resource)->assertNotFound();
        }
    }

    #[Test]
    public function it_rejects_a_missing_or_wrong_token(): void
    {
        $this->getJson('api/v1/tracks')->assertUnauthorized();

        $this->withHeader('X-SaniTube-Api-Token', 'wrong')
            ->getJson('api/v1/tracks')
            ->assertUnauthorized();
    }

    #[Test]
    public function the_health_token_does_not_open_the_catalogue(): void
    {
        // Separate tokens exist so a monitoring system cannot read the
        // catalogue; if either token opened both, that separation would be
        // decorative.
        config(['sanitube.health.token' => 'health-token']);

        $this->withHeader('X-SaniTube-Health-Token', 'health-token')
            ->getJson('api/v1/tracks')
            ->assertUnauthorized();
    }

    #[Test]
    public function the_catalogue_api_is_throttled(): void
    {
        config(['sanitube.api.rate_limit_per_minute' => 3]);
        Artist::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->get_('api/v1/artists')->assertOk();
        }

        $this->get_('api/v1/artists')->assertStatus(429);
    }

    // Leakage

    #[Test]
    public function no_internal_id_appears_anywhere_in_a_payload(): void
    {
        $release = Release::factory()->ready(2)->create();
        app(AssignExternalIdentifier::class)(
            $release,
            ExternalIdentifierType::Upc,
            '000000000017',
            isAuthoritative: true,
        );

        $payload = $this->get_('api/v1/releases/'.$release->uuid)->assertOk()->json('data');

        $this->assertNoForbiddenKeys($payload);
    }

    #[Test]
    public function a_track_payload_leaks_neither_ids_nor_storage_locations(): void
    {
        $track = Track::factory()->ready()->create();

        $payload = $this->get_('api/v1/tracks/'.$track->uuid)->assertOk()->json('data');

        $this->assertNoForbiddenKeys($payload);
        // The master is described, never located: no disk, no path, no URL.
        $this->assertSame(['uuid', 'kind', 'duration_ms', 'byte_size', 'status'], array_keys($payload['master']));
    }

    #[Test]
    public function listing_payloads_carry_no_internal_ids(): void
    {
        Track::factory()->ready()->count(3)->create();

        foreach (['artists', 'tracks', 'releases', 'compositions'] as $resource) {
            $payload = $this->get_('api/v1/'.$resource)->assertOk()->json('data');
            $this->assertNoForbiddenKeys($payload);
        }
    }

    #[Test]
    public function routes_resolve_on_uuid_and_never_on_the_internal_id(): void
    {
        $artist = Artist::factory()->create();

        $this->get_('api/v1/artists/'.$artist->uuid)->assertOk()->assertJsonPath('data.uuid', $artist->uuid);
        $this->get_('api/v1/artists/'.$artist->id)->assertNotFound();
    }

    // Identifiers

    #[Test]
    public function revoked_identifiers_are_absent_from_the_standard_payload(): void
    {
        // A consumer reading this endpoint wants what is in force now. The
        // history stays in the database for a future audit endpoint.
        $track = Track::factory()->ready()->create();
        $assign = app(AssignExternalIdentifier::class);

        $old = $assign($track, ExternalIdentifierType::Isrc, 'FRX991700001', isAuthoritative: true);
        app(RevokeExternalIdentifier::class)($old, 'Superseded.');
        $assign($track, ExternalIdentifierType::Isrc, 'FRX991700002', isAuthoritative: true);

        $identifiers = $this->get_('api/v1/tracks/'.$track->uuid)->assertOk()->json('data.identifiers');

        $this->assertSame(['FRX991700002'], array_column($identifiers, 'value'));
    }

    // Pagination

    #[Test]
    public function the_cursor_walks_the_whole_collection_without_gaps_or_repeats(): void
    {
        $created = Artist::factory()->count(7)->create()->pluck('uuid')->all();

        $seen = [];
        $response = $this->get_('api/v1/artists', ['per_page' => 3]);

        while (true) {
            $response->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'uuid'));

            $next = $response->json('meta.next_cursor') ?? $response->json('next_cursor');

            if (! is_string($next) || $next === '') {
                break;
            }

            $response = $this->get_('api/v1/artists', ['per_page' => 3, 'cursor' => $next]);
        }

        sort($created);
        sort($seen);
        $this->assertSame($created, $seen);
    }

    #[Test]
    public function an_invalid_cursor_is_rejected_rather_than_silently_ignored(): void
    {
        Artist::factory()->count(2)->create();

        // Silently restarting from page one while answering 200 is
        // indistinguishable from normal output, so an undecodable cursor is a
        // validation error like any other bad parameter.
        $this->get_('api/v1/artists', ['cursor' => 'not-a-real-cursor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cursor');
    }

    #[Test]
    public function per_page_outside_the_supported_range_is_a_validation_error(): void
    {
        // Silently clamping would let a caller believe they had paged through
        // the whole catalogue when they had not.
        $this->get_('api/v1/tracks', ['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->get_('api/v1/tracks', ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->get_('api/v1/tracks', ['per_page' => 100])->assertOk();
    }

    // Filters

    #[Test]
    public function a_known_filter_narrows_the_collection(): void
    {
        Track::factory()->ready()->create();
        Track::factory()->create();

        $ready = $this->get_('api/v1/tracks', ['status' => 'READY'])->assertOk()->json('data');

        $this->assertCount(1, $ready);
        $this->assertSame('READY', $ready[0]['status']);
    }

    #[Test]
    public function an_invalid_filter_value_is_a_validation_error(): void
    {
        $this->get_('api/v1/tracks', ['status' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->get_('api/v1/tracks', ['source' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('source');
        $this->get_('api/v1/releases', ['type' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    #[Test]
    public function an_unknown_filter_is_a_validation_error(): void
    {
        // `?stat=READY` silently returning everything would be read as "the
        // whole catalogue is READY".
        $this->get_('api/v1/tracks', ['stat' => 'READY'])->assertStatus(422)->assertJsonValidationErrors('stat');
    }

    /**
     * Recursively asserts that nothing in the payload exposes internal state.
     */
    private function assertNoForbiddenKeys(mixed $payload, string $path = 'data'): void
    {
        $forbidden = [
            'id', 'disk', 'path', 'url', 'sha256', 'original_filename', 'mime_type',
            'composition_id', 'master_asset_id', 'cover_asset_id', 'parent_asset_id',
            'duplicate_of_asset_id', 'artist_id', 'track_id', 'release_id', 'contributor_id',
            'identifiable_id', 'identifiable_type', 'active_marker',
        ];

        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains(
                    $key,
                    $forbidden,
                    sprintf('[%s.%s] exposes internal state.', $path, $key),
                );
            }

            $this->assertNoForbiddenKeys($value, $path.'.'.$key);
        }
    }
}
