<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Events\ExternalIdentifierRevoked;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\ExternalIdentifierRevocation;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * Invariant I5, plus the identifier lifecycle.
 *
 * The scenario behind most of these is the same one: roughly a third of the
 * initial catalogue was distributed elsewhere and already carries ISRCs that
 * exist in royalty statements SaniTube cannot edit. Minting a second one would
 * split a recording's earnings between two identifiers, and nobody would
 * notice until a statement failed to reconcile months later.
 */
final class ExternalIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private AssignExternalIdentifier $assign;

    private RevokeExternalIdentifier $revoke;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assign = app(AssignExternalIdentifier::class);
        $this->revoke = app(RevokeExternalIdentifier::class);
    }

    // Entity compatibility

    #[Test]
    public function an_isrc_cannot_be_attached_to_a_release(): void
    {
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('cannot be assigned to [Release]');

        ($this->assign)(Release::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');
    }

    #[Test]
    public function a_upc_cannot_be_attached_to_a_track(): void
    {
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('cannot be assigned to [Track]');

        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Upc, '000000000017');
    }

    #[Test]
    public function an_iswc_cannot_be_attached_to_a_track(): void
    {
        $this->expectException(ExternalIdentifierException::class);

        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Iswc, 'T1234567890');
    }

    #[Test]
    public function an_ipi_cannot_be_attached_to_an_artist(): void
    {
        // IPI identifies the party that gets paid, and an Artist is a stage
        // name rather than a payable party.
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('cannot be assigned to [Artist]');

        ($this->assign)(Artist::factory()->create(), ExternalIdentifierType::Ipi, '00123456789');
    }

    #[Test]
    public function each_global_type_attaches_to_its_own_entity(): void
    {
        $isrc = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FR-X99-17-00001');
        $upc = ($this->assign)(Release::factory()->create(), ExternalIdentifierType::Upc, '0-00000-000178');
        $iswc = ($this->assign)(Composition::factory()->create(), ExternalIdentifierType::Iswc, 'T-123456789-0');
        $ipi = ($this->assign)(Contributor::factory()->create(), ExternalIdentifierType::Ipi, '00123456789');

        // Normalisation is what makes the uniqueness constraints meaningful:
        // the same identifier written three ways must become one value.
        $this->assertSame('FRX991700001', $isrc->value);
        $this->assertSame('000000000178', $upc->value);
        $this->assertSame('T1234567890', $iswc->value);
        $this->assertSame('00123456789', $ipi->value);
    }

    #[Test]
    public function a_malformed_identifier_is_refused(): void
    {
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('is not a well-formed ISRC');

        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'not-an-isrc');
    }

    // Namespaces

    #[Test]
    public function a_vendor_identifier_requires_a_namespace(): void
    {
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('requires a namespace');

        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::DspId, 'abc123');
    }

    #[Test]
    public function a_global_identifier_refuses_a_namespace(): void
    {
        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('must use an empty namespace');

        ($this->assign)(
            Track::factory()->create(),
            ExternalIdentifierType::Isrc,
            'FRX991700001',
            namespace: 'somewhere',
        );
    }

    #[Test]
    public function the_same_identifier_type_may_be_active_under_two_namespaces(): void
    {
        // Two services each issue their own id for the same recording. Neither
        // may block the other, which is exactly why namespace is part of every
        // uniqueness rule.
        $track = Track::factory()->create();

        $first = ($this->assign)($track, ExternalIdentifierType::DspId, 'X1', namespace: 'service-a');
        $second = ($this->assign)($track, ExternalIdentifierType::DspId, 'X2', namespace: 'service-b');

        $this->assertTrue($first->isActive());
        $this->assertTrue($second->isActive());
        $this->assertSame(2, $track->externalIdentifiers()->whereNotNull('active_marker')->count());
    }

    #[Test]
    public function a_second_active_identifier_in_the_same_namespace_is_refused(): void
    {
        $track = Track::factory()->create();
        ($this->assign)($track, ExternalIdentifierType::DspId, 'X1', namespace: 'service-a');

        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('already carries an active authoritative DSP_ID');

        ($this->assign)($track, ExternalIdentifierType::DspId, 'X2', namespace: 'service-a');
    }

    #[Test]
    public function namespaces_are_compared_case_insensitively(): void
    {
        $track = Track::factory()->create();
        ($this->assign)($track, ExternalIdentifierType::DspId, 'X1', namespace: 'Service-A');

        $this->expectException(ExternalIdentifierException::class);

        ($this->assign)($track, ExternalIdentifierType::DspId, 'X2', namespace: 'service-a');
    }

    // The core guarantee

    #[Test]
    public function a_track_cannot_carry_two_active_canonical_isrcs(): void
    {
        $track = Track::factory()->create();
        ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700001', isAuthoritative: true);

        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('already carries an active authoritative ISRC');

        ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700002', isAuthoritative: true);
    }

    #[Test]
    public function the_database_refuses_a_second_active_identifier_even_without_the_service(): void
    {
        // The service gives a useful error; the unique index is what makes the
        // rule true. Writing straight to the model bypasses the former and
        // must still hit the latter.
        $track = Track::factory()->create();
        ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700001', isAuthoritative: true);

        $this->expectException(QueryException::class);

        ExternalIdentifier::query()->create([
            'identifiable_type' => $track->getMorphClass(),
            'identifiable_id' => $track->id,
            'type' => ExternalIdentifierType::Isrc,
            'namespace' => '',
            'value' => 'FRX991700003',
            'is_authoritative' => true,
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
            'active_marker' => ExternalIdentifier::ACTIVE,
        ]);
    }

    #[Test]
    public function a_revoked_isrc_coexists_with_its_replacement(): void
    {
        $track = Track::factory()->create();
        $first = ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700001', isAuthoritative: true);

        ($this->revoke)($first, 'Registrant code corrected.');

        $second = ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700002', isAuthoritative: true);

        $this->assertTrue($first->fresh()?->isRevoked());
        $this->assertTrue($second->isActive());
        $this->assertSame(2, $track->externalIdentifiers()->count());
    }

    #[Test]
    public function any_number_of_revoked_identifiers_may_coexist(): void
    {
        $track = Track::factory()->create();

        foreach (['FRX991700001', 'FRX991700002', 'FRX991700003'] as $value) {
            $identifier = ($this->assign)($track, ExternalIdentifierType::Isrc, $value, isAuthoritative: true);
            ($this->revoke)($identifier, 'Superseded.');
        }

        $this->assertSame(3, $track->externalIdentifiers()->whereNull('active_marker')->count());
        $this->assertSame(0, $track->externalIdentifiers()->whereNotNull('active_marker')->count());
    }

    #[Test]
    public function the_same_value_cannot_be_issued_to_two_entities(): void
    {
        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');

        $this->expectException(QueryException::class);

        ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');
    }

    // Legacy protection

    #[Test]
    public function a_legacy_track_keeps_the_isrc_it_arrived_with(): void
    {
        $track = Track::factory()->legacyImport()->create();
        ($this->assign)(
            $track,
            ExternalIdentifierType::Isrc,
            'FRX991400123',
            ExternalIdentifierSource::Import,
            isAuthoritative: true,
        );

        $this->assertTrue($this->assign->hasActive($track, ExternalIdentifierType::Isrc));

        try {
            ($this->assign)(
                $track,
                ExternalIdentifierType::Isrc,
                'FRX992500999',
                ExternalIdentifierSource::Generated,
                isAuthoritative: true,
            );
            $this->fail('A second ISRC was minted for an already-distributed recording.');
        } catch (ExternalIdentifierException) {
            $this->assertSame('FRX991400123', $track->externalIdentifiers()->sole()->value);
        }
    }

    #[Test]
    public function a_legacy_release_keeps_the_upc_it_arrived_with(): void
    {
        $release = Release::factory()->create();
        ($this->assign)($release, ExternalIdentifierType::Upc, '000000000017', isAuthoritative: true);

        $this->expectException(ExternalIdentifierException::class);

        ($this->assign)($release, ExternalIdentifierType::Upc, '000000000024', isAuthoritative: true);
    }

    #[Test]
    public function reassigning_the_same_value_is_idempotent(): void
    {
        // An import that is re-run must not fail halfway through.
        $track = Track::factory()->create();

        $first = ($this->assign)($track, ExternalIdentifierType::Isrc, 'FRX991700001');
        $second = ($this->assign)($track, ExternalIdentifierType::Isrc, 'FR-X99-17-00001');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $track->externalIdentifiers()->count());
    }

    // Immutability

    #[Test]
    public function identity_fields_cannot_be_edited(): void
    {
        $identifier = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');

        foreach (['value' => 'FRX991700002', 'namespace' => 'x', 'is_authoritative' => true] as $field => $value) {
            $fresh = $identifier->fresh();
            $this->assertNotNull($fresh);

            try {
                $fresh->{$field} = $value;
                $fresh->save();
                $this->fail(sprintf('Expected [%s] to be immutable.', $field));
            } catch (ExternalIdentifierException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function an_identifier_cannot_be_deleted(): void
    {
        $identifier = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');

        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $identifier->delete();
    }

    #[Test]
    public function a_revoked_identifier_cannot_be_reactivated(): void
    {
        $identifier = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');
        ($this->revoke)($identifier, 'Withdrawn.');

        $fresh = $identifier->fresh();
        $this->assertNotNull($fresh);

        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('cannot be reactivated');

        $fresh->active_marker = ExternalIdentifier::ACTIVE;
        $fresh->save();
    }

    // Revocation

    #[Test]
    public function revocation_writes_a_separate_record_and_clears_the_marker(): void
    {
        Event::fake([ExternalIdentifierRevoked::class]);

        $identifier = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');

        $revocation = ($this->revoke)($identifier, 'Assigned to the wrong recording.');

        $this->assertInstanceOf(ExternalIdentifierRevocation::class, $revocation);
        $this->assertSame('Assigned to the wrong recording.', $revocation->reason);
        $this->assertNull($identifier->fresh()?->active_marker);
        // The row itself survives: downstream systems still hold this value.
        $this->assertDatabaseHas('external_identifiers', ['value' => 'FRX991700001']);

        Event::assertDispatched(ExternalIdentifierRevoked::class);
    }

    #[Test]
    public function an_identifier_cannot_be_revoked_twice(): void
    {
        $identifier = ($this->assign)(Track::factory()->create(), ExternalIdentifierType::Isrc, 'FRX991700001');
        ($this->revoke)($identifier, 'First.');

        $this->expectException(ExternalIdentifierException::class);
        $this->expectExceptionMessage('has already been revoked');

        ($this->revoke)($identifier, 'Second.');
    }
}
