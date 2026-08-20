<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditSubject;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\ManuallyAssignableIdentifiers;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * CAT-005 — an identifier becomes something a person can enter.
 *
 * `AssignExternalIdentifier` and `RevokeExternalIdentifier` were built in
 * ARCH-002 and had **no caller anywhere** — not a screen, not an API route, not
 * even a console command. The consequence was not a missing button: REL-004 had
 * just made "no UPC" and "no ISRC" visible as the things that block every
 * delivery, and there was no way in the product to supply either. Telling
 * somebody what is wrong while withholding the fix is worse than saying
 * nothing, because it looks like the platform is working.
 *
 * Most of what is asserted here is what the screen refuses to do:
 *
 *   - **It never mints.** Every value is one a person typed. A recording
 *     distributed elsewhere already has an ISRC, and a second minted here would
 *     split its earnings across two numbers — which surfaces months later in a
 *     royalty report that no longer reconciles.
 *   - **It never accepts a distributor's identifier from a person.** A typed
 *     DISTRIBUTOR_RELEASE_ID would be a delivery reference for a delivery that
 *     never happened, sitting beside references genuinely received.
 *   - **It never lets one entity revoke another's.** Binding on a uuid alone
 *     would make every identifier in the installation reachable from any
 *     release the caller can see.
 *   - **It never lets the request choose its own provenance.**
 */
final class IdentifiersFromTheScreenTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------------------- access

    #[Test]
    public function assigning_and_revoking_are_behind_authentication(): void
    {
        $release = Release::factory()->create();

        $this->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'UPC', 'value' => '088515001233'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function a_read_only_member_cannot_touch_an_identifier(): void
    {
        // The hidden form is presentation; the route middleware is the check.
        $release = $this->releaseWithUpc();
        $upc = $this->activeUpc($release);
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)
            ->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'EAN', 'value' => '0885150012347'])
            ->assertForbidden();

        $this->actingAs($member)
            ->delete('/releases/'.$release->uuid.'/identifiers/'.$upc->uuid, ['reason' => 'Not mine to withdraw.'])
            ->assertForbidden();

        $this->assertFalse($upc->refresh()->isRevoked());
    }

    // ------------------------------------------------------------- assigning

    #[Test]
    public function a_person_can_record_a_upc_they_hold(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the release builder's identifiers
        // section. Before this ticket AssignExternalIdentifier had no caller
        // anywhere in the application — not even a console command.
        $release = Release::factory()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'UPC', 'value' => '088515001233'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $upc = $this->activeUpc($release);

        $this->assertSame('088515001233', $upc->value);
    }

    #[Test]
    public function the_provenance_is_the_servers_and_a_request_cannot_choose_it(): void
    {
        // A forged DISTRIBUTOR provenance is indistinguishable afterwards from
        // a real one, and the platform would show a number nobody sent us
        // beside numbers a distributor did.
        $release = Release::factory()->create();

        $this->actingAs($this->user())->post('/releases/'.$release->uuid.'/identifiers', [
            'type' => 'UPC',
            'value' => '088515001233',
            'source' => 'DISTRIBUTOR',
            'is_authoritative' => true,
        ])->assertSessionHasNoErrors();

        $upc = $this->activeUpc($release);

        $this->assertSame(ExternalIdentifierSource::Manual, $upc->source);
        $this->assertFalse($upc->is_authoritative);
    }

    #[Test]
    public function a_distributors_identifier_cannot_be_typed_in_by_a_person(): void
    {
        // The whole reason the assignable list is narrower than the registries
        // allow. A typed DISTRIBUTOR_RELEASE_ID would record a delivery
        // reference for a delivery that never happened.
        $release = Release::factory()->create();

        foreach (['DISTRIBUTOR_RELEASE_ID', 'DSP_ID', 'DSP_URL'] as $forbidden) {
            $this->actingAs($this->user())
                ->post('/releases/'.$release->uuid.'/identifiers', ['type' => $forbidden, 'value' => 'whatever'])
                ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_TYPE_NOT_ASSIGNABLE']);
        }

        $this->assertSame(0, ExternalIdentifier::query()->count());
    }

    #[Test]
    public function an_isrc_cannot_be_put_on_a_release_nor_a_upc_on_a_track(): void
    {
        // Refused by the assignable list before the normaliser is even asked,
        // so a nonsense type and a well-formed one on the wrong thing are
        // answered identically.
        $release = Release::factory()->create();
        $track = Track::factory()->ready()->create();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'ISRC', 'value' => 'FRZ032400001'])
            ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_TYPE_NOT_ASSIGNABLE']);

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/identifiers', ['type' => 'UPC', 'value' => '088515001233'])
            ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_TYPE_NOT_ASSIGNABLE']);
    }

    #[Test]
    public function a_malformed_identifier_comes_back_as_a_code(): void
    {
        // REL-002. The domain's sentence is written for a log — one of this
        // exception's messages names a fully-qualified class — so the code is
        // what travels.
        $track = Track::factory()->ready()->create();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/identifiers', ['type' => 'ISRC', 'value' => 'NOT-AN-ISRC'])
            ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_MALFORMED']);
    }

    #[Test]
    public function a_second_identifier_of_the_same_kind_is_refused_rather_than_stacked(): void
    {
        // Assigning a second would orphan the one already in circulation. The
        // screen has to say so; silently keeping both would leave the platform
        // unable to answer which one a store was given.
        $release = $this->releaseWithUpc();

        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'UPC', 'value' => '885150012341'])
            ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_ALREADY_ACTIVE']);

        $this->assertSame('088515001233', $this->activeUpc($release)->value);
    }

    // ------------------------------------------------------------- revoking

    #[Test]
    public function revoking_keeps_the_row_and_frees_the_slot(): void
    {
        // Revocation is not deletion. An identifier that reached a distributor
        // exists in reports and store metadata SaniTube does not control.
        $release = $this->releaseWithUpc();
        $upc = $this->activeUpc($release);

        $this->actingAs($this->user())
            ->delete('/releases/'.$release->uuid.'/identifiers/'.$upc->uuid, ['reason' => 'Reissued by the label.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($upc->refresh()->isRevoked());
        $this->assertSame(1, ExternalIdentifier::query()->count(), 'The row stays.');

        // And the entity is free to receive a replacement, which is the point
        // of freeing the slot rather than editing the value.
        $this->actingAs($this->user())
            ->post('/releases/'.$release->uuid.'/identifiers', ['type' => 'UPC', 'value' => '885150012341'])
            ->assertSessionHasNoErrors();

        $this->assertSame('885150012341', $this->activeUpc($release)->value);
    }

    #[Test]
    public function a_reason_is_required_and_nothing_is_revoked_without_one(): void
    {
        // "Revoked, no reason given" is a row nobody can act on years later,
        // which is exactly when it will be read.
        $release = $this->releaseWithUpc();
        $upc = $this->activeUpc($release);

        $this->actingAs($this->user())
            ->delete('/releases/'.$release->uuid.'/identifiers/'.$upc->uuid, ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($upc->refresh()->isRevoked());
    }

    #[Test]
    public function an_identifier_cannot_be_revoked_through_an_entity_it_does_not_belong_to(): void
    {
        // Route-model binding on a uuid alone would make every identifier in
        // the installation reachable from any release the caller can see.
        $mine = $this->releaseWithUpc();
        $theirs = $this->releaseWithUpc('885150012341');

        $theirUpc = $this->activeUpc($theirs);

        $this->actingAs($this->user())
            ->delete('/releases/'.$mine->uuid.'/identifiers/'.$theirUpc->uuid, ['reason' => 'Reaching across.'])
            ->assertNotFound();

        $this->assertFalse($theirUpc->refresh()->isRevoked());
    }

    #[Test]
    public function revoking_the_same_identifier_twice_is_refused_by_code(): void
    {
        $release = $this->releaseWithUpc();
        $upc = $this->activeUpc($release);
        $url = '/releases/'.$release->uuid.'/identifiers/'.$upc->uuid;

        $this->actingAs($this->user())->delete($url, ['reason' => 'Reissued by the label.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user())->delete($url, ['reason' => 'Reissued by the label.'])
            ->assertSessionHasErrors(['identifier' => 'IDENTIFIER_ALREADY_REVOKED']);
    }

    // ----------------------------------------------------------- the record

    #[Test]
    public function both_acts_are_audited_and_neither_records_the_number_itself(): void
    {
        // An ISRC is what a store, a collecting society and a royalty statement
        // all key on. Who typed it is worth knowing years later — but copying
        // the value into a table nobody scrubs is not how to know it, and the
        // identifier row already holds it with its own provenance.
        $track = Track::factory()->ready()->create();

        $this->actingAs($this->user())
            ->post('/catalog/tracks/'.$track->uuid.'/identifiers', ['type' => 'ISRC', 'value' => 'FRZ032400001'])
            ->assertSessionHasNoErrors();

        $assigned = AuditEvent::query()->where('action', AuditAction::IdentifierAssigned->value)->firstOrFail();

        $this->assertSame(ExternalIdentifier::query()->firstOrFail()->uuid, $assigned->subject_uuid);

        // The subject is the identifier, not the track. Filing it under the
        // track would scatter an identifier's history through everything else
        // that happened to a recording, and would lose which of two ISRCs an
        // entry was about once a replacement exists — the exact moment somebody
        // goes looking.
        $this->assertSame(AuditSubject::ExternalIdentifier, $assigned->subject);

        $this->assertStringNotContainsString('FRZ032400001', (string) json_encode($assigned->context));

        $url = '/catalog/tracks/'.$track->uuid.'/identifiers/'.ExternalIdentifier::query()->firstOrFail()->uuid;

        $this->actingAs($this->user())->delete($url, ['reason' => 'Reissued by the label.'])
            ->assertSessionHasNoErrors();

        $revoked = AuditEvent::query()->where('action', AuditAction::IdentifierRevoked->value)->firstOrFail();

        $this->assertSame(AuditSubject::ExternalIdentifier, $revoked->subject);

        // The reason is not copied here either. It is on the revocation record,
        // which is where somebody reading the identifier's history will look,
        // and free text duplicated into a second table is how an audit log
        // fills with whatever anybody felt like writing.
        $this->assertStringNotContainsString('Reissued', (string) json_encode($revoked->context));
    }

    // -------------------------------------------------------- what is offered

    #[Test]
    public function the_screen_offers_exactly_what_the_controller_will_accept(): void
    {
        // A form offering a type the server refuses is a form that fails on
        // submit for a reason nobody can see. Both read the same list.
        $release = Release::factory()->create();

        $page = $this->actingAs($this->user())->get('/releases/'.$release->uuid)->assertOk();

        /** @var array<string, mixed> $props */
        $props = $page->viewData('page')['props']['release'];

        $this->assertSame(['UPC', 'EAN'], $props['assignable_identifier_types']);
    }

    #[Test]
    public function no_identifier_a_third_party_issues_is_ever_offered(): void
    {
        // Asserted against the whole map rather than the two entries somebody
        // remembered, so a later ticket adding an entity cannot quietly open
        // the door this one closed.
        foreach ([Release::factory()->create(), Track::factory()->ready()->create()] as $entity) {
            foreach (ManuallyAssignableIdentifiers::for($entity) as $type) {
                $this->assertNotContains($type, [
                    ExternalIdentifierType::DistributorReleaseId,
                    ExternalIdentifierType::DistributorTrackId,
                    ExternalIdentifierType::DspId,
                    ExternalIdentifierType::DspUrl,
                ], sprintf('[%s] is issued by somebody else and must never be typed in.', $type->value));
            }
        }
    }

    // ----------------------------------------------------------- fixtures

    private function releaseWithUpc(string $value = '088515001233'): Release
    {
        $release = Release::factory()->create();

        ($this->app->make(AssignExternalIdentifier::class))($release, ExternalIdentifierType::Upc, $value);

        return $release;
    }

    private function activeUpc(Release $release): ExternalIdentifier
    {
        /** @var ExternalIdentifier $identifier */
        $identifier = ExternalIdentifier::query()
            ->active()
            ->where('identifiable_type', $release->getMorphClass())
            ->where('identifiable_id', $release->getKey())
            ->where('type', ExternalIdentifierType::Upc->value)
            ->firstOrFail();

        return $identifier;
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
