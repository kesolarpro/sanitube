<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

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
    public function a_distributor_that_cannot_be_asked_leaves_the_delivery_unknown(): void
    {
        $release = $this->readyRelease();
        $delivery = $this->openUnconfirmed($release);

        $this->distributor->withoutSubmissionLookup();

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
            decidedBy: 42,
            note: 'Found it in the Too Lost dashboard under 12 March, awaiting review.',
        );

        $this->assertSame(DistributionDeliveryStatus::Submitted, $resolved->status);
        $this->assertSame('TL-99321', $resolved->external_release_id);

        $attempt = $resolved->attempts()->orderByDesc('id')->firstOrFail();

        // The one place in this module where a value the platform never
        // received is written as though it had been. Who typed it is recorded.
        $this->assertSame(42, $attempt->decided_by);
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
            decidedBy: 42,
            note: 'Nothing in the dashboard and support confirmed no record.',
        );

        // FAILED rather than deleted: the attempt history is what makes the
        // decision reviewable afterwards.
        $this->assertSame(DistributionDeliveryStatus::Failed, $resolved->status);
        $this->assertTrue($resolved->status->isSubmittable());
        $this->assertSame(42, $resolved->attempts()->orderByDesc('id')->firstOrFail()->decided_by);
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
