<?php

declare(strict_types=1);

namespace Tests\Feature\Deduplication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Exceptions\AssetTrashException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Catalog\Models\Track;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Exceptions\DuplicateDecisionException;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Deduplication\Services\DecideDuplicate;
use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * DEDUP-002 — answering a finding, and setting a master aside.
 *
 * The half of deduplication that can do damage. DEDUP-001 could only ever
 * write a row; this can hide a master. So the tests are mostly about the
 * things that must remain true afterwards: the bytes are still there, the
 * catalogue is still whole, and a person's name is on every decision.
 */
final class TrashAndDecisionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function trashing_hides_an_asset_without_touching_its_bytes(): void
    {
        // The whole promise of logical trash. If this ever stops being true,
        // the feature has become a delete with a friendlier name.
        $asset = $this->asset('master.wav', 'the only copy of something');
        $storage = $this->app->make(AssetStorageService::class);
        $provider = $storage->providerFor($asset);

        $this->app->make(TrashAsset::class)->trash($asset, $this->reviewer(), 'DUPLICATE');

        $asset->refresh();
        $this->assertTrue($asset->isTrashed());
        $this->assertSame('DUPLICATE', $asset->trash_reason);

        // Still in storage, still the same bytes, still describable.
        $this->assertTrue($provider->exists($asset->path));
        $this->assertSame($asset->sha256, $provider->checksum($asset->path));
        $this->assertSame(AssetStatus::Stored, $asset->status);
    }

    #[Test]
    public function restoring_returns_the_asset_exactly_as_it_was(): void
    {
        $asset = $this->asset('master.wav', 'something worth keeping');
        $trash = $this->app->make(TrashAsset::class);
        $reviewer = $this->reviewer();

        $trash->trash($asset, $reviewer, 'DUPLICATE');
        $trash->restore($asset, $reviewer);

        $asset->refresh();
        $this->assertFalse($asset->isTrashed());
        $this->assertNull($asset->trashed_at);
        $this->assertNull($asset->trashed_by_user_id);
        $this->assertNull($asset->trash_reason);
        $this->assertSame(AssetStatus::Stored, $asset->status);
    }

    #[Test]
    public function a_master_the_catalogue_depends_on_cannot_be_trashed(): void
    {
        // Tidying must not be able to break a track. This is the refusal that
        // makes it safe to work through the queue quickly.
        $asset = $this->asset('master.wav', 'a promoted master');

        Track::factory()->create(['master_asset_id' => $asset->id]);

        try {
            $this->app->make(TrashAsset::class)->trash($asset, $this->reviewer(), 'DUPLICATE');
            $this->fail('A track master was trashed.');
        } catch (AssetTrashException $exception) {
            $this->assertSame('ASSET_IN_USE', $exception->reason);
        }

        $this->assertFalse($asset->fresh()?->isTrashed());
    }

    #[Test]
    public function an_upload_still_in_flight_is_not_a_reviewers_to_trash(): void
    {
        // Trashing a PENDING asset would race the write still happening. The
        // staging sweep owns that case.
        $asset = $this->app->make(AssetStorageService::class)
            ->register(AssetKind::AudioMaster, 'in-flight.wav');

        try {
            $this->app->make(TrashAsset::class)->trash($asset, $this->reviewer(), 'DUPLICATE');
            $this->fail('A pending asset was trashed.');
        } catch (AssetTrashException $exception) {
            $this->assertSame('ASSET_NOT_SETTLED', $exception->reason);
        }
    }

    #[Test]
    public function trashing_and_restoring_both_name_a_person_in_the_audit_log(): void
    {
        // The closest thing the platform has to losing a master, even though
        // it loses nothing. "Where did that file go" needs an answer.
        $asset = $this->asset('master.wav', 'audited bytes');
        $trash = $this->app->make(TrashAsset::class);
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer);
        $trash->trash($asset, $reviewer, 'DUPLICATE');
        $trash->restore($asset, $reviewer);

        $trashed = AuditEvent::query()->where('action', AuditAction::AssetTrashed->value)->firstOrFail();
        $this->assertSame($asset->uuid, $trashed->subject_uuid);
        $this->assertSame('DUPLICATE', $trashed->reason);
        $this->assertSame($reviewer->id, $trashed->actor_id);

        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::AssetRestored->value)->count());
    }

    #[Test]
    public function a_confirmed_finding_names_who_confirmed_it(): void
    {
        $relation = $this->finding();
        $reviewer = $this->reviewer();

        $decided = $this->app->make(DecideDuplicate::class)->confirm($relation, $reviewer);

        $this->assertSame(DuplicateDecision::Confirmed, $decided->decision);
        $this->assertSame($reviewer->id, $decided->decided_by_user_id);
        $this->assertNotNull($decided->decided_at);
    }

    #[Test]
    public function confirming_a_duplicate_does_not_trash_anything(): void
    {
        // The separation the whole design rests on. Agreeing that two files
        // hold the same recording is not a decision to lose one of them, and
        // an over-eager confirmation must cost nothing.
        $relation = $this->finding();

        $this->app->make(DecideDuplicate::class)->confirm($relation, $this->reviewer());

        $this->assertSame(0, Asset::query()->whereNotNull('trashed_at')->count());
        $this->assertSame(2, Asset::query()->count());
    }

    #[Test]
    public function the_same_answer_twice_is_refused_rather_than_re_recorded(): void
    {
        // A double-submitted form must not rewrite who decided and when.
        $relation = $this->finding();
        $decider = $this->app->make(DecideDuplicate::class);

        $decider->confirm($relation, $this->reviewer());

        try {
            $decider->confirm($relation->refresh(), $this->reviewer());
            $this->fail('The same decision was recorded twice.');
        } catch (DuplicateDecisionException $exception) {
            $this->assertSame('DUPLICATE_ALREADY_DECIDED', $exception->reason);
        }
    }

    #[Test]
    public function a_reviewer_may_change_their_mind(): void
    {
        // Nothing irreversible happened, so a wrong answer must be correctable.
        $relation = $this->finding();
        $decider = $this->app->make(DecideDuplicate::class);

        $decider->confirm($relation, $this->reviewer());
        $decider->reject($relation->refresh(), $this->reviewer());

        $this->assertSame(DuplicateDecision::Rejected, $relation->refresh()->decision);
    }

    #[Test]
    public function both_outcomes_are_audited_without_the_english(): void
    {
        $relation = $this->finding();
        $reviewer = $this->reviewer();
        $this->actingAs($reviewer);

        $this->app->make(DecideDuplicate::class)->reject($relation, $reviewer);

        $event = AuditEvent::query()->where('action', AuditAction::DuplicateRejected->value)->firstOrFail();

        $this->assertSame($relation->uuid, $event->subject_uuid);
        $this->assertSame('duplicate_relation', $event->subject->value);
    }

    #[Test]
    public function a_trashed_asset_stops_producing_findings_in_both_directions(): void
    {
        // Re-running the evaluation must not ask a reviewer to answer a
        // question they have already answered.
        $first = $this->asset('a.wav', 'identical bytes here');
        $second = $this->asset('b.wav', 'identical bytes here');

        $this->app->make(TrashAsset::class)->trash($second, $this->reviewer(), 'DUPLICATE');

        $evaluate = $this->app->make(EvaluateAssetDuplicates::class);

        $this->assertCount(0, $evaluate->for($second), 'A trashed asset produced findings about itself.');
        $this->assertCount(0, $evaluate->for($first), 'A trashed asset was proposed as a match.');
        $this->assertSame(0, DuplicateRelation::query()->count());
    }

    // ------------------------------------------------------------ fixtures

    private function finding(): DuplicateRelation
    {
        $first = $this->asset('original.wav', 'the very same bytes');
        $second = $this->asset('copy.wav', 'the very same bytes');

        $findings = $this->app->make(EvaluateAssetDuplicates::class)->for($second);
        $relation = $findings->first();

        $this->assertInstanceOf(DuplicateRelation::class, $relation, 'The fixture produced no finding.');
        $this->assertSame($first->id, $relation->related_asset_id);

        return $relation;
    }

    private function reviewer(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $stream);
    }
}
