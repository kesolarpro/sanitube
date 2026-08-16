<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Distribution\Testing\WithoutSubmissionLookup;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\DeliveryDetailQuery;
use SaniTube\Ui\Queries\DeliveryIndexQuery;
use SaniTube\Ui\Queries\ReleaseDistributionQuery;
use Tests\TestCase;

/**
 * The screens over the one irreversible act in the platform.
 *
 * Five claims, and each of them is a way the interface could quietly become a
 * second implementation of DIST-001:
 *
 *   - **Nothing that identifies somebody else's account leaves the server.**
 *     No `external_release_id`, no `idempotency_key` — the second is the token
 *     that makes a repeat submission recognisable, and a reader holding it
 *     could construct one.
 *   - **FAILED and REJECTED are never the same thing.** "Nobody could ask" and
 *     "they said no" are opposite answers, and treating an outage as a verdict
 *     is how a package goes out unchecked.
 *   - **UNKNOWN is not a state that has been checked.** A delivery nobody has
 *     synced has `last_synced_at` null, and that is what is shown.
 *   - **Rendering a screen never talks to a distributor.** Refreshing a page
 *     must not be an outbound request, and an outage must not take the page
 *     down with it.
 *   - **`actions` is presentation, never authorisation.** Every flag has a test
 *     that posts past the hidden button and is refused anyway.
 */
final class DistributionScreensTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    private ?FakeDistributor $fake = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Registered before anything can resolve the config-built one.
        // Registering lazily meant a test that submitted first was talking to
        // a different instance than the one it later inspected — which read as
        // "the submission never happened".
        $this->fake();
    }

    // --------------------------------------------------------------- access

    #[Test]
    public function every_distribution_screen_is_behind_authentication(): void
    {
        $delivery = $this->delivery();

        $this->get('/distribution')->assertRedirect(route('login'));
        $this->get('/distribution/'.$delivery->uuid)->assertRedirect(route('login'));
        $this->get('/releases/'.$delivery->release?->uuid.'/distribution')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_may_watch_a_delivery_and_do_nothing_to_it(): void
    {
        $delivery = $this->delivery();
        $release = $delivery->release;
        $member = $this->user(UserRole::Member, 'member@example.test');

        // Distribution is the one ability that is not simply "can write": a
        // member who can assemble a release still cannot send it to a store.
        $this->actingAs($member)->get('/distribution')->assertOk();
        $this->actingAs($member)->get('/distribution/'.$delivery->uuid)->assertOk();

        $this->actingAs($member)
            ->get('/releases/'.$release?->uuid.'/distribution/fake/verdict')
            ->assertForbidden();
        $this->actingAs($member)
            ->post('/releases/'.$release?->uuid.'/distribution/fake/submit')
            ->assertForbidden();
        $this->actingAs($member)->post('/distribution/'.$delivery->uuid.'/sync')->assertForbidden();
        $this->actingAs($member)->post('/distribution/'.$delivery->uuid.'/takedown')->assertForbidden();

        $this->assertSame(DistributionDeliveryStatus::Draft, $delivery->refresh()->status);
    }

    #[Test]
    public function a_member_is_not_offered_the_actions_either(): void
    {
        $payload = $this->detail($this->delivery(), $this->user(UserRole::Member, 'member@example.test'));

        $this->assertFalse($payload['actions']['may_distribute']);
        $this->assertFalse($payload['actions']['can_submit']);
        $this->assertFalse($payload['actions']['can_sync']);
        $this->assertFalse($payload['actions']['can_request_takedown']);
    }

    #[Test]
    public function a_deactivated_account_cannot_submit_anything(): void
    {
        $release = $this->deliverableRelease();
        $user = $this->user(UserRole::Owner, 'gone@example.test');
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertRedirect(route('login'));

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    // ------------------------------------------------------------- leakage

    #[Test]
    public function nothing_identifying_somebody_elses_account_reaches_the_screen(): void
    {
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        $this->assertNotNull($delivery->external_release_id);

        $payload = $this->detail($delivery, $this->owner());
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        // The reference identifies a record in somebody else's account; the
        // key is what makes a repeat submission recognisable.
        $this->assertArrayNotHasKey('external_release_id', $payload);
        $this->assertArrayNotHasKey('idempotency_key', $payload);
        $this->assertStringNotContainsString($delivery->external_release_id, $encoded);
        $this->assertStringNotContainsString($delivery->idempotency_key, $encoded);

        // The question a label actually has, answered.
        $this->assertTrue($payload['has_external_reference']);

        $row = $this->app->make(DeliveryIndexQuery::class)->paginate()['rows'][0];
        $this->assertArrayNotHasKey('external_release_id', $row);
        $this->assertArrayNotHasKey('idempotency_key', $row);
        $this->assertTrue($row['has_external_reference']);
    }

    #[Test]
    public function a_failure_is_reported_without_the_url_it_came_from(): void
    {
        $release = $this->deliverableRelease();

        $this->fake()->down();

        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');
        $delivery->forceFill([
            'failure_reason' => "cURL error 7: Failed to connect to distributor.example.test port 443\nStack trace:\n#0 /var/www/vendor/...",
        ])->save();

        $reason = $this->detail($delivery->refresh(), $this->owner())['failure_reason'];

        $this->assertIsString($reason);

        // One line, capped. The outage path stores a raw exception message and
        // those quote hosts and paths; the stack trace is not a thing a label
        // acts on and is not a thing this platform publishes.
        $this->assertStringNotContainsString("\n", $reason);
        $this->assertStringNotContainsString('/var/www', $reason);
        $this->assertLessThanOrEqual(201, mb_strlen($reason));
    }

    // ------------------------------------------- unknown, failed, rejected

    #[Test]
    public function a_delivery_nobody_has_asked_about_says_so(): void
    {
        $delivery = $this->delivery();

        $payload = $this->detail($delivery, $this->owner());

        // Null, not a date and not a claim that nothing changed. The
        // distributor may have accepted this weeks ago.
        $this->assertNull($payload['last_synced_at']);
        $this->assertFalse($payload['has_external_reference']);
    }

    #[Test]
    public function an_outage_leaves_the_delivery_failed_and_never_rejected(): void
    {
        $release = $this->deliverableRelease();

        $this->fake()->down();

        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        // FAILED is retryable and is not a verdict the distributor gave.
        // REJECTED here would say the package was looked at and refused.
        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);

        $payload = $this->detail($delivery, $this->owner());
        $this->assertSame('FAILED', $payload['status']);
        $this->assertTrue($payload['actions']['can_submit'], 'A failed delivery must remain retryable.');
    }

    #[Test]
    public function a_refusal_by_the_distributor_is_recorded_as_a_verdict(): void
    {
        $release = $this->deliverableRelease();

        $this->fake()->rejecting(['The genre code is not one we recognise.']);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertSessionHasErrors(['distribution' => 'DISTRIBUTOR_REJECTED']);

        $delivery = DistributionDelivery::query()->firstOrFail();
        $this->assertSame(DistributionDeliveryStatus::Draft, $delivery->status);

        $attempts = $this->detail($delivery, $this->owner())['attempts'];
        $this->assertNotSame([], $attempts);
        $this->assertSame('REJECTED', $attempts[array_key_last($attempts)]['outcome']);
    }

    // --------------------------------------------------------- the screens

    #[Test]
    public function rendering_a_delivery_never_asks_the_distributor_anything(): void
    {
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        // Every call to this distributor now throws. A screen that polled on
        // render would 500; refreshing a page must not be an outbound request,
        // and a distributor's outage must not take the screen down with it.
        $this->fake()->down();

        $this->actingAs($this->owner())->get('/distribution/'.$delivery->uuid)->assertOk();
        $this->actingAs($this->owner())->get('/distribution')->assertOk();
        $this->actingAs($this->owner())->get('/releases/'.$release->uuid.'/distribution')->assertOk();
    }

    #[Test]
    public function the_list_is_ordered_by_when_the_record_was_made(): void
    {
        // Submission dates deliberately in the opposite order to creation. A
        // delivery is opened when somebody decides to send something and
        // submitted whenever the package is finally ready, so the two orders
        // genuinely disagree — and a list that reshuffles as things are sent
        // is one nobody can work down.
        $first = $this->delivery([
            'created_at' => now()->subDays(3),
            'submitted_at' => now()->subHour(),
        ]);
        $second = $this->delivery([
            'created_at' => now()->subDay(),
            'submitted_at' => now()->subDays(5),
        ]);

        $rows = $this->app->make(DeliveryIndexQuery::class)->paginate()['rows'];

        $this->assertSame([$first->uuid, $second->uuid], array_column($rows, 'uuid'));
    }

    #[Test]
    public function the_list_filters_by_status_and_by_distributor(): void
    {
        $this->delivery(['status' => DistributionDeliveryStatus::Failed]);
        $this->delivery(['provider' => 'other']);

        $query = $this->app->make(DeliveryIndexQuery::class);

        $failed = $query->paginate(['status' => 'FAILED'])['rows'];
        $this->assertCount(1, $failed);

        // A provider that is no longer configured still filters. It is how
        // somebody finds the rows a removed distributor left behind.
        $other = $query->paginate(['provider' => 'other'])['rows'];
        $this->assertCount(1, $other);
        $this->assertSame('other', $other[0]['provider']);
    }

    #[Test]
    public function a_cursor_from_another_list_is_refused_rather_than_crashing(): void
    {
        $this->delivery();

        $foreign = base64_encode(json_encode(['provider' => 'x', '_pointsToNextItems' => true], JSON_THROW_ON_ERROR));

        $this->actingAs($this->owner())
            ->get('/distribution?cursor='.urlencode($foreign))
            ->assertStatus(422);
    }

    #[Test]
    public function the_query_refuses_a_foreign_cursor_on_its_own(): void
    {
        // Separate from the HTTP test, which the form request alone satisfies.
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(DeliveryIndexQuery::class)->paginate(
            [],
            base64_encode(json_encode(['provider' => 'x', '_pointsToNextItems' => true], JSON_THROW_ON_ERROR)),
        );
    }

    #[Test]
    public function a_page_of_deliveries_costs_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->delivery();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->app->make(DeliveryIndexQuery::class)->paginate();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries, 'The delivery list counts or loads per row.');
    }

    #[Test]
    public function a_delivery_naming_a_distributor_that_is_gone_still_opens(): void
    {
        $delivery = $this->delivery(['provider' => 'retired']);

        $payload = $this->detail($delivery, $this->owner());

        // A delivery outlives a configuration change. 500ing here would hide
        // the very record somebody is trying to look at.
        $this->assertFalse($payload['distributor']['known']);
        $this->assertFalse($payload['distributor']['available']);
        $this->assertNull($payload['distributor']['sandbox']);
        $this->assertFalse($payload['actions']['can_submit']);

        $this->actingAs($this->owner())->get('/distribution/'.$delivery->uuid)->assertOk();
    }

    // ------------------------------------------------------- the send screen

    #[Test]
    public function the_send_screen_never_offers_the_disabled_distributor(): void
    {
        $release = $this->deliverableRelease();

        $providers = array_column(
            $this->send($release, $this->owner())['destinations'],
            'provider',
        );

        // `none` is the name of *not having* a distributor. Offering it as a
        // destination would be offering a submission that cannot happen.
        $this->assertNotContains(DistributorManager::DISABLED, $providers);
        $this->assertContains('fake', $providers);
    }

    #[Test]
    public function a_sandbox_destination_is_labelled_as_one(): void
    {
        $release = $this->deliverableRelease();

        $destination = $this->destination($this->send($release, $this->owner()), 'fake');

        // A rehearsal is legitimate, which is exactly why it has to be
        // visible: a sandbox delivery that looks real is discovered weeks
        // later, when stores have nothing.
        $this->assertTrue($destination['sandbox']);
    }

    #[Test]
    public function a_draft_release_is_not_offered_for_submission(): void
    {
        $release = Release::factory()->create();

        $payload = $this->send($release, $this->owner());

        $this->assertFalse($payload['release_is_ready']);
        $this->assertFalse($this->destination($payload, 'fake')['can_submit']);
    }

    #[Test]
    public function an_unavailable_distributor_offers_neither_a_verdict_nor_a_submission(): void
    {
        $release = $this->deliverableRelease();

        $this->fake()->unavailable();

        $destination = $this->destination($this->send($release, $this->owner()), 'fake');

        // Not configured is not approval. Offering a submit button that the
        // domain will refuse is a promise the platform cannot keep.
        $this->assertFalse($destination['available']);
        $this->assertFalse($destination['can_submit']);
        $this->assertFalse($destination['can_validate']);
    }

    #[Test]
    public function asking_for_a_verdict_creates_no_delivery(): void
    {
        $release = $this->deliverableRelease();

        $this->actingAs($this->owner())
            ->get('/releases/'.$release->uuid.'/distribution/fake/verdict')
            ->assertOk()
            ->assertJsonPath('valid', true);

        // A "let me check" that left a DRAFT delivery behind would become a
        // record a colleague reads as an intention.
        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    #[Test]
    public function a_verdict_reports_what_is_wrong_without_handing_anything_over(): void
    {
        // No UPC, no ISRC: the identifier errors ValidateDelivery adds.
        $release = Release::factory()->ready()->create();

        $this->actingAs($this->owner())
            ->get('/releases/'.$release->uuid.'/distribution/fake/verdict')
            ->assertOk()
            ->assertJsonPath('valid', false);

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    #[Test]
    public function an_unknown_distributor_is_refused_by_code(): void
    {
        $release = $this->deliverableRelease();

        $this->actingAs($this->owner())
            ->get('/releases/'.$release->uuid.'/distribution/nosuch/verdict')
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNKNOWN_DISTRIBUTOR');

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/nosuch/submit')
            ->assertSessionHasErrors(['distribution' => 'UNKNOWN_DISTRIBUTOR']);
    }

    // ------------------------------------------------------- the submission

    #[Test]
    public function submitting_hands_the_release_over_once_and_opens_the_record(): void
    {
        $release = $this->deliverableRelease();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertRedirect('/distribution/'.DistributionDelivery::query()->firstOrFail()->uuid);

        $this->assertSame(1, DistributionDelivery::query()->count());
        $this->assertSame(1, $this->fake()->submitCalls);
    }

    #[Test]
    public function submitting_twice_hands_it_over_once(): void
    {
        $release = $this->deliverableRelease();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertRedirect();

        // The retry a double-submitted form produces. A second delivery is
        // what a store reads as a duplicate, and it is what gets a label's
        // whole catalogue flagged rather than one release.
        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertSessionHasErrors(['distribution' => 'ALREADY_SUBMITTED']);

        $this->assertSame(1, DistributionDelivery::query()->count());
        $this->assertSame(1, $this->fake()->submitCalls);
    }

    #[Test]
    public function a_draft_release_cannot_be_handed_over_from_the_screen(): void
    {
        $release = Release::factory()->create();

        // The payload draws no submit button. The domain refuses it anyway,
        // which is the property that makes the flag safe to compute.
        $this->assertFalse($this->destination($this->send($release, $this->owner()), 'fake')['can_submit']);

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertSessionHasErrors(['distribution' => 'RELEASE_NOT_READY']);

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    #[Test]
    public function an_unavailable_distributor_cannot_be_submitted_to(): void
    {
        $release = $this->deliverableRelease();

        $this->fake()->unavailable();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertSessionHasErrors(['distribution' => 'DISTRIBUTOR_UNAVAILABLE']);

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    // ------------------------------------------------------ sync & takedown

    #[Test]
    public function checking_with_the_distributor_records_when_it_was_asked(): void
    {
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        $delivery->forceFill(['last_synced_at' => null])->save();

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/sync')
            ->assertRedirect();

        $this->assertNotNull($delivery->refresh()->last_synced_at);
    }

    #[Test]
    public function checking_a_delivery_that_was_never_handed_over_asks_nobody(): void
    {
        $delivery = $this->delivery();

        // Down: any outbound call throws. Syncing a delivery that was never
        // handed over must ask nobody, so this stays a redirect rather than a
        // recorded failure.
        $this->fake()->down();

        $this->actingAs($this->owner())->post('/distribution/'.$delivery->uuid.'/sync')->assertRedirect();

        $delivery->refresh();

        $this->assertNull($delivery->last_synced_at);
        $this->assertSame(0, $delivery->attempts()->count());
        $this->assertFalse($this->detail($delivery, $this->owner())['actions']['can_sync']);
    }

    #[Test]
    public function a_takedown_cannot_be_asked_for_before_anything_is_live(): void
    {
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        $this->assertFalse($this->detail($delivery, $this->owner())['actions']['can_request_takedown']);

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/takedown')
            ->assertSessionHasErrors(['distribution' => 'NOT_TAKEDOWNABLE']);
    }

    #[Test]
    public function a_live_delivery_can_be_asked_to_come_down(): void
    {
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');
        $delivery->forceFill(['status' => DistributionDeliveryStatus::Live])->save();

        $this->assertTrue($this->detail($delivery->refresh(), $this->owner())['actions']['can_request_takedown']);

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/takedown')
            ->assertRedirect();

        $this->assertSame(DistributionDeliveryStatus::TakedownRequested, $delivery->refresh()->status);
    }

    // ------------------------------------------- the unconfirmed submission

    #[Test]
    public function an_unconfirmed_delivery_is_offered_answers_rather_than_a_retry(): void
    {
        $delivery = $this->unconfirmed();

        $payload = $this->detail($delivery, $this->owner());

        // The distributor may already hold the package. A submit button here
        // is a duplicate delivery with a nice label on it.
        $this->assertFalse($payload['actions']['can_submit']);
        $this->assertTrue($payload['actions']['can_reconcile']);
        $this->assertTrue($payload['actions']['can_resolve_manually']);
    }

    #[Test]
    public function reconciling_from_the_screen_adopts_what_the_distributor_holds(): void
    {
        $release = $this->deliverableRelease();

        // The package lands and the answer never comes back.
        $this->fake()->swallowingSubmissions();

        $this->actingAs($this->owner())
            ->post('/releases/'.$release->uuid.'/distribution/fake/submit')
            ->assertRedirect();

        $delivery = DistributionDelivery::query()->firstOrFail();
        $this->assertSame(DistributionDeliveryStatus::SubmittedUnconfirmed, $delivery->status);

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/reconcile')
            ->assertRedirect();

        $delivery->refresh();

        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);
        $this->assertNotNull($delivery->external_release_id);

        // Handed over once, which is the entire point.
        $this->assertSame(1, $this->fake()->submitCalls);
    }

    #[Test]
    public function a_distributor_that_cannot_be_asked_leaves_the_screen_saying_so(): void
    {
        $delivery = $this->unconfirmed();

        // Re-registered without the lookup capability, which is how a real
        // adapter for a provider with no such endpoint arrives.
        $this->app->make(DistributorManager::class)
            ->register('fake', new WithoutSubmissionLookup($this->fake()));

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/reconcile')
            ->assertRedirect();

        // Unchanged, and a person is still the only way out.
        $this->assertSame(
            DistributionDeliveryStatus::SubmittedUnconfirmed,
            $delivery->refresh()->status,
        );
        $this->assertTrue($this->detail($delivery, $this->owner())['actions']['can_resolve_manually']);
    }

    #[Test]
    public function a_person_records_what_they_found_and_is_named_for_it(): void
    {
        $delivery = $this->unconfirmed();

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/resolve', [
                'arrived' => true,
                'external_release_id' => 'TL-99321',
                'note' => 'Found it in their dashboard under 12 March, awaiting review.',
            ])
            ->assertRedirect();

        $delivery->refresh();

        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);
        $this->assertSame('TL-99321', $delivery->external_release_id);

        // The reviewer comes from the session, never the request: a decision
        // that says who made it is only worth something if the caller could
        // not choose.
        $this->assertSame(
            $this->owner()->getKey(),
            $delivery->attempts()->orderByDesc('id')->firstOrFail()->decided_by,
        );
    }

    #[Test]
    public function claiming_it_arrived_without_a_reference_is_refused_from_the_screen(): void
    {
        $delivery = $this->unconfirmed();

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/resolve', [
                'arrived' => true,
                'note' => 'It is definitely there somewhere.',
            ])
            ->assertSessionHasErrors(['distribution' => 'RESOLUTION_NEEDS_REFERENCE']);

        $this->assertSame(
            DistributionDeliveryStatus::SubmittedUnconfirmed,
            $delivery->refresh()->status,
        );
    }

    #[Test]
    public function overruling_the_platform_without_a_reason_is_refused_by_the_form(): void
    {
        $delivery = $this->unconfirmed();

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/resolve', ['arrived' => false, 'note' => '  '])
            ->assertSessionHasErrors('note');

        $this->assertSame(
            DistributionDeliveryStatus::SubmittedUnconfirmed,
            $delivery->refresh()->status,
        );
    }

    #[Test]
    public function a_member_can_neither_reconcile_nor_resolve(): void
    {
        $delivery = $this->unconfirmed();
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->post('/distribution/'.$delivery->uuid.'/reconcile')->assertForbidden();
        $this->actingAs($member)
            ->post('/distribution/'.$delivery->uuid.'/resolve', [
                'arrived' => true,
                'external_release_id' => 'TL-1',
                'note' => 'Trust me on this one.',
            ])
            ->assertForbidden();

        $this->assertSame(
            DistributionDeliveryStatus::SubmittedUnconfirmed,
            $delivery->refresh()->status,
        );
    }

    #[Test]
    public function there_is_nothing_to_reconcile_about_a_delivery_whose_state_is_known(): void
    {
        $delivery = $this->delivery();

        $payload = $this->detail($delivery, $this->owner());
        $this->assertFalse($payload['actions']['can_reconcile']);
        $this->assertFalse($payload['actions']['can_resolve_manually']);

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/reconcile')
            ->assertSessionHasErrors(['distribution' => 'NOT_RECONCILABLE']);
    }

    // ------------------------------------------------------------ navigation

    #[Test]
    public function distribution_is_reachable_from_the_navigation(): void
    {
        $tree = (new NavigationTree)->for($this->owner());

        $distribution = null;

        foreach ($tree as $item) {
            if ($item['key'] === 'distribution') {
                $distribution = $item;
            }
        }

        $this->assertNotNull($distribution);
        $this->assertTrue($distribution['available']);
        $this->assertSame('/distribution', $distribution['href']);
    }

    #[Test]
    public function the_screen_is_told_when_a_reference_was_typed_by_a_person(): void
    {
        // The one value in this module the platform never received. A screen
        // that showed it exactly like a reference the distributor returned
        // would let it be read as provider-verified, and a typo in it produces
        // a delivery nobody can poll or take down.
        $delivery = $this->unconfirmed();

        $this->actingAs($this->owner())
            ->post('/distribution/'.$delivery->uuid.'/resolve', [
                'arrived' => true,
                'external_release_id' => 'TL-99321',
                'note' => 'Found it in the distributor dashboard, awaiting review.',
            ])
            ->assertRedirect();

        $payload = $this->detail($delivery->refresh(), $this->owner());

        $this->assertTrue($payload['has_external_reference']);
        $this->assertSame('MANUAL_OPERATOR', $payload['external_reference_source']);

        // And the reference itself still does not travel: it identifies a
        // record in somebody else's account.
        $this->assertStringNotContainsString('TL-99321', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function the_screen_is_told_when_a_reference_came_from_the_distributor(): void
    {
        // Through the real submission path, so the provenance is the one the
        // service wrote rather than one a fixture asserted into place.
        $release = $this->deliverableRelease();
        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'fake');

        $payload = $this->detail($delivery->refresh(), $this->owner());

        $this->assertTrue($payload['has_external_reference']);
        $this->assertSame('PROVIDER_RESPONSE', $payload['external_reference_source']);
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function detail(DistributionDelivery $delivery, User $viewer): array
    {
        return $this->app->make(DeliveryDetailQuery::class)->forDelivery($delivery, $viewer);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(Release $release, User $viewer): array
    {
        return $this->app->make(ReleaseDistributionQuery::class)->forRelease($release, $viewer);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function destination(array $payload, string $provider): array
    {
        /** @var list<array<string, mixed>> $destinations */
        $destinations = $payload['destinations'];

        foreach ($destinations as $destination) {
            if ($destination['provider'] === $provider) {
                return $destination;
            }
        }

        $this->fail(sprintf('No [%s] destination in the payload.', $provider));
    }

    /**
     * A release the domain would actually accept: READY, with a UPC and an
     * ISRC on every track. Anything less and every submission test would be
     * testing the identifier errors instead.
     */
    private function deliverableRelease(): Release
    {
        $release = Release::factory()->ready()->create();
        $assign = $this->app->make(AssignExternalIdentifier::class);

        $assign(
            entity: $release,
            type: ExternalIdentifierType::Upc,
            value: '012345678905',
            source: ExternalIdentifierSource::Manual,
        );

        $isrc = 100;

        foreach ($release->tracks()->get() as $track) {
            /** @var Track $track */
            $assign(
                entity: $track,
                type: ExternalIdentifierType::Isrc,
                value: sprintf('FRZ0325%05d', $isrc++),
                source: ExternalIdentifierSource::Manual,
            );
        }

        return $release->refresh();
    }

    private function unconfirmed(): DistributionDelivery
    {
        return $this->delivery([
            'status' => DistributionDeliveryStatus::SubmittedUnconfirmed,
            'failure_reason' => 'Read timed out waiting for the distributor.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(array $attributes = []): DistributionDelivery
    {
        $release = Release::factory()->create();

        $delivery = new DistributionDelivery;

        $delivery->forceFill(array_merge([
            'release_id' => $release->getKey(),
            'provider' => 'fake',
            'status' => DistributionDeliveryStatus::Draft,
            'idempotency_key' => 'key-'.$release->uuid,
        ], $attributes))->save();

        return $delivery;
    }

    /**
     * The shipped fake, registered once so a test can break its connection or
     * switch it off and have the screens see the same instance.
     */
    private function fake(): FakeDistributor
    {
        if ($this->fake === null) {
            $this->fake = new FakeDistributor('fake');
            $this->app->make(DistributorManager::class)->register('fake', $this->fake);
        }

        return $this->fake;
    }

    private function owner(): User
    {
        return $this->owner ??= $this->user(UserRole::Owner, 'owner@example.test');
    }

    private function user(UserRole $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true]);
    }
}
