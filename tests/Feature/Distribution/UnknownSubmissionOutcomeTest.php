<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Distribution\Contracts\SupportsSubmissionLookup;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Enums\ExternalReferenceSource;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Distribution\Testing\WithoutSubmissionLookup;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;
use Throwable;

/**
 * DIST-001-H1 — when SaniTube cannot say whether the package arrived.
 *
 * DIST-001 treats every failure during submission as `FAILED`, which is
 * retryable. That is right for a connection that was refused and wrong for a
 * read that timed out: the request may have reached the distributor, the
 * package may be in their system, and a retry against a provider that does not
 * honour idempotency keys is a **second delivery** — the exact outcome the
 * whole module exists to prevent.
 *
 * The stable key is a mitigation, not a guarantee. No contract can make a
 * provider honour it.
 *
 * So the claim under test is that the platform can say **"I do not know"**, and
 * that saying it costs a retry rather than granting one:
 *
 *   - A failure while *preparing* is FAILED. Nothing was submitted.
 *   - A failure the adapter can prove never left is FAILED.
 *   - Anything else during *submitting* is `SUBMITTED_UNCONFIRMED`, which is
 *     not submittable, not pending, and not a verdict.
 *   - It leaves that state by being reconciled, or by a person who has looked.
 */
final class UnknownSubmissionOutcomeTest extends TestCase
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

    // -------------------------------------------------- classifying a failure

    #[Test]
    public function a_submission_that_is_never_answered_is_unknown_rather_than_failed(): void
    {
        $release = $this->readyRelease();

        // The package lands and the answer never comes back.
        $this->distributor->swallowingSubmissions();

        $delivery = $this->submit()->handle($release, 'fake');

        // FAILED here would be a lie in the one direction that costs a label
        // its catalogue: it says "nothing happened, try again".
        $this->assertSame(DistributionDeliveryStatus::SubmittedUnconfirmed, $delivery->status);
        $this->assertTrue($delivery->status->isUnknown());
        $this->assertFalse($delivery->status->isSubmittable());
    }

    #[Test]
    public function an_unknown_outcome_is_recorded_as_unknown_and_not_as_a_failure(): void
    {
        $release = $this->readyRelease();
        $this->distributor->swallowingSubmissions();

        $delivery = $this->submit()->handle($release, 'fake');

        $attempt = $delivery->attempts()->orderByDesc('id')->firstOrFail();

        // FAILED is what retry logic reads. "It did not work" and "we do not
        // know whether it worked" call for opposite next moves.
        $this->assertSame(DistributionAttemptOutcome::Unknown, $attempt->outcome);
        $this->assertSame(DistributionAction::Submit, $attempt->action);
    }

    #[Test]
    public function a_connection_the_adapter_knows_was_refused_stays_retryable(): void
    {
        $release = $this->readyRelease();

        // SubmissionNotSent: the adapter *knows* nothing left this machine.
        $this->distributor->refusingConnections();

        $delivery = $this->submit()->handle($release, 'fake');

        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);
        $this->assertTrue($delivery->status->isSubmittable());
        $this->assertSame(0, $this->distributor->submitCalls);
    }

    #[Test]
    public function a_failure_while_preparing_stays_retryable(): void
    {
        $release = $this->readyRelease();

        // `failingPreparation()` and not `down()`: `down()` breaks
        // validateRelease() too, so the submission is refused before
        // preparation is ever reached — which is how this test silently
        // covered the validation path instead. A mutation caught it.
        $this->distributor->failingPreparation();

        $delivery = $this->submit()->handle($release, 'fake');

        $this->assertSame(DistributionDeliveryStatus::Failed, $delivery->status);
        $this->assertTrue($delivery->status->isSubmittable());
        $this->assertSame(0, $this->distributor->submitCalls);
    }

    /**
     * What a real client says when an upload dies.
     *
     * Not "the upload was interrupted" — that is the polite sentence a fake
     * produces. A client throws the request it was making, and for
     * S3-compatible storage or a distributor's signed endpoint that request
     * *is* the credential. Both the delivery's `failure_reason` and the
     * attempt's `response_summary` are durable columns: read by the detail
     * screen, by the internal API, and carried into every database backup.
     *
     * `DeliveryDetailQuery` already knew — its comment said the outage path
     * stores an exception message "and those quote URLs" — and answered with a
     * length limit. A limit removes the end of a message.
     */
    #[Test]
    public function a_providers_own_words_are_not_stored_with_the_address_they_name(): void
    {
        $release = $this->readyRelease();

        $this->distributor->failingPreparation(
            'PUT https://ingest.distributor.example/upload/abc'
                .'?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefcafe failed with 403',
        );

        $delivery = $this->submit()->handle($release, 'fake');

        $stored = (string) $delivery->failure_reason;
        $attempt = (string) $delivery->attempts()->latest('id')->firstOrFail()->response_summary;

        foreach (['failure_reason' => $stored, 'response_summary' => $attempt] as $column => $value) {
            $this->assertStringNotContainsString('X-Amz-Signature', $value, $column.' stored a signature.');
            $this->assertStringNotContainsString('ingest.distributor.example', $value, $column.' stored an address.');
            $this->assertStringContainsString('403', $value, $column.' was scrubbed to nothing.');
        }
    }

    /**
     * The internal API is a boundary too, and it had no test at all.
     *
     * `GET /api/v1/distributions/{delivery}` publishes `failure_reason`.
     * Writes are clean from now on; rows written before they were are already
     * in the column, and this is the read that covers them. A token-guarded
     * machine caller is a narrower audience than a browser, not a trusted one
     * — what it is handed can be logged, cached and forwarded by whatever
     * holds the token.
     */
    #[Test]
    public function the_delivery_api_does_not_publish_an_address_from_a_stored_failure(): void
    {
        config(['sanitube.api.internal_token' => 'internal-token']);

        $delivery = $this->submit()->handle($this->readyRelease(), 'fake');

        $delivery->forceFill([
            'failure_reason' => 'PUT https://ingest.distributor.example/upload/abc'
                .'?X-Amz-Signature=deadbeefcafe failed with 403',
        ])->save();

        $reason = (string) $this->withHeader('X-SaniTube-Api-Token', 'internal-token')
            ->getJson('api/v1/distributions/'.$delivery->uuid)
            ->assertOk()
            ->json('data.failure_reason');

        $this->assertStringNotContainsString('X-Amz-Signature', $reason);
        $this->assertStringNotContainsString('ingest.distributor.example', $reason);
        $this->assertStringContainsString('403', $reason, 'Scrubbed to nothing says less than an empty cell.');
    }

    #[Test]
    public function an_unconfirmed_delivery_cannot_be_submitted_again(): void
    {
        $release = $this->readyRelease();
        $this->distributor->swallowingSubmissions();

        $this->submit()->handle($release, 'fake');

        // The whole ticket in one assertion. The distributor may already have
        // the package; offering a retry is offering a duplicate delivery.
        $this->expectException(DistributionException::class);

        try {
            $this->submit()->handle($release, 'fake');
        } catch (DistributionException $exception) {
            $this->assertSame('ALREADY_SUBMITTED', $exception->reason);
            $this->assertSame(1, $this->distributor->submitCalls);

            throw $exception;
        }
    }

    // ------------------------------------------------------------ reconciling

    #[Test]
    public function reconciling_adopts_the_submission_the_distributor_turns_out_to_hold(): void
    {
        $release = $this->readyRelease();
        $this->distributor->swallowingSubmissions();

        $delivery = $this->submit()->handle($release, 'fake');
        $this->assertNull($delivery->external_release_id);

        // Asked by idempotency key: the answer the timed-out response would
        // have carried.
        $reconciled = $this->submit()->reconcile($delivery);

        $this->assertSame(DistributionDeliveryStatus::Submitted, $reconciled->status);
        $this->assertNotNull($reconciled->external_release_id);
        $this->assertNull($reconciled->failure_reason);
        $this->assertNotNull($reconciled->submitted_at);

        // And it was handed over exactly once, which is the point.
        $this->assertSame(1, $this->distributor->submitCalls);
    }

    #[Test]
    public function reconciling_a_submission_that_never_arrived_makes_it_retryable_again(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        // The distributor looked and holds nothing under this key. That is
        // evidence, and it is what makes a retry safe.
        $reconciled = $this->submit()->reconcile($delivery);

        $this->assertSame(DistributionDeliveryStatus::Failed, $reconciled->status);
        $this->assertTrue($reconciled->status->isSubmittable());
    }

    #[Test]
    public function a_reconciliation_that_ends_in_failure_is_logged_as_a_reconciliation(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $reconciled = $this->submit()->reconcile($delivery);

        // The history is what a person reads when deciding whether to hand the
        // release over again, and "the submission failed" is a different
        // account of the world from "we asked, and they never got it". The
        // first invites a retry; the second is the evidence that makes one
        // safe. A log that says the wrong one misdescribes the only
        // irreversible act in the platform.
        $attempt = $reconciled->attempts()->orderByDesc('id')->firstOrFail();

        $this->assertSame(DistributionAction::Reconcile, $attempt->action);
        $this->assertSame(DistributionAttemptOutcome::Failed, $attempt->outcome);

        // One row for one event. "The lookup succeeded" and "the delivery
        // failed" are the same moment seen twice.
        $this->assertSame(1, $reconciled->attempts()
            ->where('action', DistributionAction::Reconcile->value)
            ->count());
    }

    #[Test]
    public function a_second_answer_to_the_same_open_question_is_refused(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $this->submit()->resolveManually(
            $delivery,
            arrived: true,
            externalReleaseId: 'TL-99321',
            decidedBy: $this->operator()->getKey(),
            note: 'Found it in the distributor dashboard, awaiting review.',
        );

        // `$delivery` still says SUBMITTED_UNCONFIRMED — it is the instance a
        // second operator's request would be holding, loaded before the first
        // one answered. Checking the in-memory status and then writing is
        // exactly what `handle()` refuses to do, and for the same reason: two
        // people can reach opposite conclusions about the same stuck delivery,
        // and the loser must not silently overwrite a recorded decision.
        $this->assertTrue($delivery->status->isUnknown());

        try {
            $this->submit()->resolveManually(
                $delivery,
                arrived: false,
                externalReleaseId: null,
                decidedBy: $this->operator('second@example.test')->getKey(),
                note: 'Nothing in the dashboard as far as I can see.',
            );
            $this->fail('A closed question was answered twice.');
        } catch (DistributionException $exception) {
            $this->assertSame('NOT_RECONCILABLE', $exception->reason);
        }

        $fresh = $delivery->fresh();

        // The first answer stands, and the second left no trace pretending it
        // was a decision.
        $this->assertNotNull($fresh);
        $this->assertSame(DistributionDeliveryStatus::Submitted, $fresh->status);
        $this->assertSame('TL-99321', $fresh->external_release_id);
        $this->assertSame(1, $fresh->attempts()->whereNotNull('decided_by')->count());
    }

    #[Test]
    public function a_reconciliation_cannot_overwrite_an_answer_a_person_already_gave(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $this->submit()->resolveManually(
            $delivery,
            arrived: true,
            externalReleaseId: 'TL-99321',
            decidedBy: $this->operator()->getKey(),
            note: 'Found it in the distributor dashboard, awaiting review.',
        );

        // The other direction: a reconcile request already in flight when the
        // person answered. The fake holds nothing, so an unguarded reconcile
        // would drag a delivery that a person confirmed *did* arrive back to
        // FAILED — and FAILED is retryable, which is a second delivery.
        try {
            $this->submit()->reconcile($delivery);
            $this->fail('A reconciliation overwrote a recorded decision.');
        } catch (DistributionException $exception) {
            $this->assertSame('NOT_RECONCILABLE', $exception->reason);
        }

        $fresh = $delivery->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(DistributionDeliveryStatus::Submitted, $fresh->status);
        $this->assertSame('TL-99321', $fresh->external_release_id);
    }

    #[Test]
    public function a_distributor_that_cannot_be_asked_leaves_the_delivery_unknown(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        // Registered *without* the lookup capability. Not a flag on the fake:
        // the whole point of SupportsSubmissionLookup is that an adapter which
        // cannot search says so by its type, and the service checks before it
        // calls anything.
        $this->app->make(DistributorManager::class)
            ->register('fake', new WithoutSubmissionLookup($this->distributor));

        $reconciled = $this->submit()->reconcile($delivery);

        // "I cannot look" is not "I hold nothing". Collapsing the two would
        // turn every provider without a lookup endpoint into one that
        // confidently reports an empty account.
        $this->assertSame(DistributionDeliveryStatus::SubmittedUnconfirmed, $reconciled->status);
        $this->assertFalse($reconciled->status->isSubmittable());

        $attempt = $reconciled->attempts()->orderByDesc('id')->firstOrFail();
        $this->assertSame(DistributionAction::Reconcile, $attempt->action);
        $this->assertSame(DistributionAttemptOutcome::Unknown, $attempt->outcome);
    }

    #[Test]
    public function a_lookup_that_itself_fails_is_not_evidence_of_an_empty_account(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        // The question timed out. Moving the row on a failed question would be
        // inventing the answer.
        $this->distributor->down();

        $reconciled = $this->submit()->reconcile($delivery);

        $this->assertSame(DistributionDeliveryStatus::SubmittedUnconfirmed, $reconciled->status);
        $this->assertSame(
            DistributionAttemptOutcome::Unknown,
            $reconciled->attempts()->orderByDesc('id')->firstOrFail()->outcome,
        );
    }

    #[Test]
    public function there_is_nothing_to_reconcile_about_a_delivery_whose_state_is_known(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->submit()->handle($release, 'fake');

        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);

        $this->expectException(DistributionException::class);

        try {
            $this->submit()->reconcile($delivery);
        } catch (DistributionException $exception) {
            $this->assertSame('NOT_RECONCILABLE', $exception->reason);

            throw $exception;
        }
    }

    // ------------------------------------------------------ a person decides

    #[Test]
    public function a_person_can_record_that_the_submission_did_arrive(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $resolved = $this->submit()->resolveManually(
            $delivery,
            arrived: true,
            externalReleaseId: 'TL-99321',
            decidedBy: $this->operator()->getKey(),
            note: 'Found it in the Too Lost dashboard under 12 March, awaiting review.',
        );

        $this->assertSame(DistributionDeliveryStatus::Submitted, $resolved->status);
        $this->assertSame('TL-99321', $resolved->external_release_id);

        $attempt = $resolved->attempts()->orderByDesc('id')->firstOrFail();

        // The one place in this module where a value the platform never
        // received is written as though it had been. Who typed it is recorded.
        $this->assertSame($this->operator()->getKey(), $attempt->decided_by);
        $this->assertStringContainsString('Too Lost dashboard', (string) $attempt->response_summary);
    }

    #[Test]
    public function a_person_can_record_that_it_never_arrived(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $resolved = $this->submit()->resolveManually(
            $delivery,
            arrived: false,
            externalReleaseId: null,
            decidedBy: $this->operator()->getKey(),
            note: 'Nothing in the dashboard and support confirmed no record.',
        );

        // FAILED rather than deleted: the attempt history is what makes the
        // decision reviewable afterwards.
        $this->assertSame(DistributionDeliveryStatus::Failed, $resolved->status);
        $this->assertTrue($resolved->status->isSubmittable());
        $this->assertSame(
            $this->operator()->getKey(),
            $resolved->attempts()->orderByDesc('id')->firstOrFail()->decided_by,
        );
    }

    #[Test]
    public function claiming_it_arrived_without_a_reference_is_refused(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        // "It arrived but I cannot say under what reference" leaves a
        // SUBMITTED row nobody can ever poll or take down again.
        $this->expectException(DistributionException::class);

        try {
            $this->submit()->resolveManually($delivery, true, null, 42, 'It is definitely there.');
        } catch (DistributionException $exception) {
            $this->assertSame('RESOLUTION_NEEDS_REFERENCE', $exception->reason);
            $this->assertSame(
                DistributionDeliveryStatus::SubmittedUnconfirmed,
                $delivery->refresh()->status,
            );

            throw $exception;
        }
    }

    #[Test]
    public function overruling_the_platform_without_a_reason_is_refused(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $this->expectException(DistributionException::class);

        try {
            $this->submit()->resolveManually($delivery, false, null, 42, '   ');
        } catch (DistributionException $exception) {
            $this->assertSame('RESOLUTION_NEEDS_NOTE', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function a_person_cannot_resolve_a_delivery_whose_state_is_known(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->submit()->handle($release, 'fake');

        $this->expectException(DistributionException::class);

        $this->submit()->resolveManually($delivery, true, 'TL-1', 42, 'Because I say so.');
    }

    #[Test]
    public function an_ordinary_attempt_names_nobody(): void
    {
        $release = $this->readyRelease();
        $this->submit()->handle($release, 'fake');

        // Nullable, and null on every attempt that was a conversation with a
        // distributor. A default of "system" would make the column unable to
        // answer the only question it is for.
        $this->assertSame(
            0,
            DistributionAttempt::query()->whereNotNull('decided_by')->count(),
        );
    }

    // --------------------------------------------------------------- fixtures

    /**
     * A real person to attribute a decision to.
     *
     * `decided_by` carries a RESTRICT foreign key now, so an id nobody owns is
     * refused — which is the point. These tests used to attribute decisions to
     * user 42, an account that never existed, and the append-only log they
     * assert on could not have named anybody.
     */
    // ---------------------------------------------------------- concurrency

    /**
     * Every pair of answers two people can give the same open question.
     *
     * Whoever answers first is the answer. The second is told the question is
     * closed, and the log holds exactly one decisive row — because the failure
     * this guards against is not a lost update but a *contradiction*: one
     * operator recording "it arrived, reference TL-99321" and another "nothing
     * in the dashboard", with the delivery keeping whichever landed last.
     *
     * The reverse ordering is the dangerous one. A reconcile already in flight
     * when a person confirms the package did arrive would drag the delivery
     * back to FAILED, and FAILED is submittable — a second delivery, which is
     * the outcome this whole module exists to prevent.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function opposingAnswers(): iterable
    {
        yield 'manual arrived, then manual not-arrived' => ['manual-arrived', 'manual-missing'];
        yield 'manual not-arrived, then manual arrived' => ['manual-missing', 'manual-arrived'];
        yield 'manual arrived, then reconciliation' => ['manual-arrived', 'reconcile'];
        yield 'manual not-arrived, then reconciliation' => ['manual-missing', 'reconcile'];
        yield 'reconciliation, then manual arrived' => ['reconcile', 'manual-arrived'];
        yield 'reconciliation, then reconciliation' => ['reconcile', 'reconcile'];
    }

    #[Test]
    #[DataProvider('opposingAnswers')]
    public function only_one_answer_to_an_open_question_ever_wins(string $first, string $second): void
    {
        $release = $this->readyRelease();

        // Held by the distributor, so a reconcile has a real answer to give.
        $this->distributor->swallowingSubmissions();
        $delivery = $this->submit()->handle($release, 'fake');

        $this->assertTrue($delivery->status->isUnknown());

        $this->answer($first, $delivery);

        $settled = $delivery->fresh();
        $this->assertNotNull($settled);
        $this->assertFalse($settled->status->isUnknown(), 'The first answer did not settle the question.');

        $before = $settled->status;
        $reference = $settled->external_release_id;
        $decisions = DistributionAttempt::query()->whereNotNull('decided_by')->count();

        // `$delivery` still reads SUBMITTED_UNCONFIRMED in memory — it is the
        // instance a second operator's request would be holding, loaded before
        // the first one answered.
        try {
            $this->answer($second, $delivery);
            $this->fail('A closed question was answered twice.');
        } catch (DistributionException $exception) {
            $this->assertSame('NOT_RECONCILABLE', $exception->reason);
        }

        $after = $delivery->fresh();
        $this->assertNotNull($after);

        $this->assertSame($before, $after->status);
        $this->assertSame($reference, $after->external_release_id);
        $this->assertSame($decisions, DistributionAttempt::query()->whereNotNull('decided_by')->count());
    }

    private function answer(string $kind, DistributionDelivery $delivery): void
    {
        match ($kind) {
            'manual-arrived' => $this->submit()->resolveManually(
                $delivery,
                arrived: true,
                externalReleaseId: 'TL-99321',
                decidedBy: $this->operator()->getKey(),
                note: 'Found it in the distributor dashboard.',
            ),
            'manual-missing' => $this->submit()->resolveManually(
                $delivery,
                arrived: false,
                externalReleaseId: null,
                decidedBy: $this->operator('second@example.test')->getKey(),
                note: 'Nothing in the dashboard and support confirmed no record.',
            ),
            'reconcile' => $this->submit()->reconcile($delivery),
            default => throw new \InvalidArgumentException($kind),
        };
    }

    // ----------------------------------------------------------- provenance

    #[Test]
    public function a_reference_the_distributor_returned_is_recorded_as_such(): void
    {
        $release = $this->readyRelease();

        $delivery = $this->submit()->handle($release, 'fake');

        $this->assertSame(ExternalReferenceSource::ProviderResponse, $delivery->external_reference_source);
        $this->assertTrue($delivery->external_reference_source->isFromProvider());
    }

    #[Test]
    public function a_reference_found_by_asking_is_told_apart_from_one_that_was_returned(): void
    {
        // Both came from the provider, but one arrived in a response and the
        // other after that response was lost. Worth telling apart when
        // reconstructing what happened to a delivery.
        $release = $this->readyRelease();

        // The request arrived; the response was lost. So the distributor does
        // hold something under this key, and asking finds it.
        $this->distributor->swallowingSubmissions();
        $delivery = $this->submit()->handle($release, 'fake');

        $reconciled = $this->submit()->reconcile($delivery);

        $this->assertSame(DistributionDeliveryStatus::Submitted, $reconciled->status);
        $this->assertSame(ExternalReferenceSource::ProviderLookup, $reconciled->external_reference_source);
        $this->assertTrue($reconciled->external_reference_source->isFromProvider());
    }

    #[Test]
    public function a_reference_a_person_typed_is_never_indistinguishable_from_one_received(): void
    {
        // The correction this ticket was held open for. A hand-entered
        // reference is the one value in this module the platform never
        // received, and a typo in it produces a delivery nobody can poll or
        // take down — discovered months later by a release that will not come
        // off a store.
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $resolved = $this->submit()->resolveManually(
            $delivery,
            arrived: true,
            externalReleaseId: 'TL-99321',
            decidedBy: $this->operator()->getKey(),
            note: 'Found it in the distributor dashboard, awaiting review.',
        );

        $this->assertSame(ExternalReferenceSource::ManualOperator, $resolved->external_reference_source);
        $this->assertFalse($resolved->external_reference_source->isFromProvider());
    }

    // ----------------------------------------------------------- the capability

    #[Test]
    public function a_distributor_without_the_lookup_capability_is_never_asked(): void
    {
        // Asked of the type rather than by calling and catching. The fake
        // counts nothing here — what matters is that a provider which cannot
        // search is identified before a call is made, so an adapter never has
        // to declare a method it can only throw from.
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $manager = $this->app->make(DistributorManager::class);
        $manager->register('fake', new WithoutSubmissionLookup($this->distributor));

        // Asked of what the service will actually resolve, not of a local
        // variable whose class is already known — the guard is that a
        // registered distributor can fail this check, and it does.
        $this->assertNotInstanceOf(SupportsSubmissionLookup::class, $manager->distributor('fake'));

        $reconciled = $this->submit()->reconcile($delivery);

        $this->assertSame(DistributionDeliveryStatus::SubmittedUnconfirmed, $reconciled->status);

        // And the log says *why* nobody could say. Calling anyway and letting
        // the missing method blow up would leave the delivery in the same
        // state — so the state alone proves nothing — but it would record a
        // PHP error where an operator needs a reason, and would have made an
        // adapter responsible for a method it cannot implement.
        $attempt = $reconciled->attempts()->orderByDesc('id')->firstOrFail();

        $this->assertSame(DistributionAttemptOutcome::Unknown, $attempt->outcome);
        $this->assertStringContainsString('cannot be asked', (string) $attempt->response_summary);
        $this->assertStringNotContainsString('findSubmission', (string) $attempt->response_summary);
    }

    // ---------------------------------------------------------- accountability

    #[Test]
    public function a_person_who_decided_cannot_be_deleted_out_from_under_the_decision(): void
    {
        // RESTRICT, and neither of the alternatives. Cascading would erase the
        // history by way of the users table — exactly what an append-only log
        // exists to prevent — and nulling would keep a decision that says a
        // person made it and cannot say who, which is worse because it looks
        // complete.
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);
        $operator = $this->operator();

        $this->submit()->resolveManually(
            $delivery,
            arrived: false,
            externalReleaseId: null,
            decidedBy: $operator->getKey(),
            note: 'Nothing in the dashboard and support confirmed no record.',
        );

        // The honest outcome. SEC-001 deactivates rather than deletes, and an
        // installation that genuinely must remove somebody has to decide what
        // happens to their decisions rather than have the database decide
        // silently.
        $deleted = true;

        try {
            $operator->delete();
        } catch (Throwable) {
            $deleted = false;
        }

        $this->assertFalse($deleted, 'The person who took a recorded decision was deleted.');
        $this->assertSame(1, User::query()->whereKey($operator->getKey())->count());

        $this->assertSame(1, DistributionAttempt::query()->whereNotNull('decided_by')->count());
    }

    #[Test]
    public function a_decision_cannot_be_attributed_to_somebody_who_does_not_exist(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $this->expectException(QueryException::class);

        $this->submit()->resolveManually(
            $delivery,
            arrived: false,
            externalReleaseId: null,
            decidedBy: 987654,
            note: 'Attributed to nobody in particular.',
        );
    }

    private function operator(string $email = 'operator@example.test'): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Operator', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }

    private function submit(): SubmitDelivery
    {
        return $this->app->make(SubmitDelivery::class);
    }

    /**
     * A delivery in SUBMITTED_UNCONFIRMED without the distributor having kept
     * the submission — the "we asked and nothing is there" starting point.
     */
    private function openUnconfirmed(Release $release): DistributionDelivery
    {
        $delivery = $this->submit()->open($release, 'fake');

        $delivery->forceFill([
            'status' => DistributionDeliveryStatus::SubmittedUnconfirmed,
            'failure_reason' => 'Read timed out waiting for the distributor.',
        ])->save();

        return $delivery->refresh();
    }

    private function readyRelease(): Release
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
}
