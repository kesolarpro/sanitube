<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\DuplicateReviewQuery;
use Tests\TestCase;

/**
 * DEDUP-003 — the screen a person answers findings on.
 *
 * The queue is where deduplication either becomes useful or becomes the reason
 * a master went missing, so the tests are about who may press what, what the
 * payload is allowed to contain, and the fact that deciding and discarding stay
 * two separate acts all the way out to the routes.
 */
final class DuplicateQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_queue_is_behind_authentication(): void
    {
        $this->get('/duplicates')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_may_read_the_queue_and_decide_nothing_in_it(): void
    {
        // Knowing two files may hold the same recording is not a privilege.
        // Answering is.
        $relation = $this->finding();
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->get('/duplicates')->assertOk();

        $this->actingAs($member)->post('/duplicates/'.$relation->uuid.'/confirm')->assertForbidden();
        $this->actingAs($member)->post('/duplicates/'.$relation->uuid.'/reject')->assertForbidden();
        $this->actingAs($member)
            ->post('/assets/'.$relation->asset?->uuid.'/trash', ['reason' => 'DUPLICATE'])
            ->assertForbidden();

        $this->assertSame(DuplicateDecision::Proposed, $relation->refresh()->decision);
        $this->assertFalse($relation->asset?->fresh()?->isTrashed());
    }

    #[Test]
    public function the_queue_is_reachable_only_by_uuid(): void
    {
        $relation = $this->finding();

        $this->actingAs($this->user())
            ->post('/duplicates/'.$relation->getKey().'/confirm')
            ->assertNotFound();
    }

    #[Test]
    public function the_payload_carries_the_evidence_and_never_where_a_master_lives(): void
    {
        // Asserted against the query's own output with slashes unescaped.
        // Reading the rendered HTML instead is how this assertion goes quietly
        // defective: Inertia JSON-escapes `/` as `\/`, so a leaked object key
        // never matches the raw path and the test passes while the path is on
        // the page. It was written that way first, and a mutation that leaked
        // `path` into the payload did not fail it.
        $relation = $this->finding();
        $asset = $relation->asset;
        $this->assertInstanceOf(Asset::class, $asset);

        $encoded = (string) json_encode(
            $this->app->make(DuplicateReviewQuery::class)->paginate(),
            JSON_UNESCAPED_SLASHES,
        );

        // The evidence a reviewer needs to choose between two files.
        $this->assertStringContainsString($asset->uuid, $encoded);
        $this->assertStringContainsString('checksum_short', $encoded);
        $this->assertStringContainsString('is_measured', $encoded);

        // And nothing about where it lives. `original_filename` is on this
        // list for the same reason AssetIndexQuery puts it there: it is the
        // field that most looks like a title and is not one, and a reviewer
        // choosing which copy to keep by its name is deciding on the least
        // reliable thing about it.
        foreach (['"path"', '"disk"', '"bucket"', '"original_filename"', '"id"', '"asset_id"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, sprintf('%s reached the browser.', $forbidden));
        }

        $this->assertStringNotContainsString($asset->path, $encoded);
        $this->assertStringNotContainsString($asset->original_filename, $encoded);

        // Twelve characters are enough to compare two rows by eye; the whole
        // digest belongs in an export.
        $this->assertStringNotContainsString((string) $asset->sha256, $encoded);
    }

    #[Test]
    public function the_screen_renders_for_a_signed_in_person(): void
    {
        $this->finding();

        $this->actingAs($this->user())->get('/duplicates')->assertOk();
    }

    #[Test]
    public function a_reviewer_can_answer_a_finding(): void
    {
        $relation = $this->finding();

        $this->actingAs($this->user())
            ->post('/duplicates/'.$relation->uuid.'/confirm')
            ->assertRedirect();

        $this->assertSame(DuplicateDecision::Confirmed, $relation->refresh()->decision);
    }

    #[Test]
    public function answering_twice_comes_back_as_a_code_rather_than_an_error_page(): void
    {
        // Every refusal here is something the reviewer can act on, and the
        // code travels rather than the domain's English sentence.
        $relation = $this->finding();
        $reviewer = $this->user();

        $this->actingAs($reviewer)->post('/duplicates/'.$relation->uuid.'/confirm');

        $this->actingAs($reviewer)
            ->post('/duplicates/'.$relation->uuid.'/confirm')
            ->assertSessionHasErrors(['decision' => 'DUPLICATE_ALREADY_DECIDED']);
    }

    #[Test]
    public function confirming_a_finding_does_not_discard_anything(): void
    {
        // The separation the design rests on, asserted at the HTTP boundary
        // too: there is no request that both agrees and deletes.
        $relation = $this->finding();

        $this->actingAs($this->user())->post('/duplicates/'.$relation->uuid.'/confirm');

        $this->assertSame(0, Asset::query()->whereNotNull('trashed_at')->count());
    }

    #[Test]
    public function trashing_is_its_own_request_with_its_own_reason(): void
    {
        $relation = $this->finding();
        $asset = $relation->asset;
        $this->assertInstanceOf(Asset::class, $asset);

        $this->actingAs($this->user())
            ->post('/assets/'.$asset->uuid.'/trash', ['reason' => 'DUPLICATE'])
            ->assertRedirect();

        $asset->refresh();
        $this->assertTrue($asset->isTrashed());
        $this->assertSame('DUPLICATE', $asset->trash_reason);
    }

    #[Test]
    public function a_reason_outside_the_list_is_refused(): void
    {
        // A reason somebody typed is a reason nobody can count.
        $relation = $this->finding();

        $this->actingAs($this->user())
            ->post('/assets/'.$relation->asset?->uuid.'/trash', ['reason' => 'because I felt like it'])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($relation->asset?->fresh()?->isTrashed());
    }

    #[Test]
    public function a_refused_trash_comes_back_as_a_code(): void
    {
        $relation = $this->finding();
        $asset = $relation->asset;
        $this->assertInstanceOf(Asset::class, $asset);
        $reviewer = $this->user();

        $this->actingAs($reviewer)->post('/assets/'.$asset->uuid.'/trash', ['reason' => 'DUPLICATE']);

        $this->actingAs($reviewer)
            ->post('/assets/'.$asset->uuid.'/trash', ['reason' => 'DUPLICATE'])
            ->assertSessionHasErrors(['asset' => 'ASSET_ALREADY_TRASHED']);
    }

    #[Test]
    public function a_master_can_be_brought_back(): void
    {
        $relation = $this->finding();
        $asset = $relation->asset;
        $this->assertInstanceOf(Asset::class, $asset);
        $reviewer = $this->user();

        $this->actingAs($reviewer)->post('/assets/'.$asset->uuid.'/trash', ['reason' => 'DUPLICATE']);
        $this->actingAs($reviewer)->post('/assets/'.$asset->uuid.'/restore')->assertRedirect();

        $this->assertFalse($asset->fresh()?->isTrashed());
    }

    #[Test]
    public function the_queue_can_be_narrowed_to_what_is_still_unanswered(): void
    {
        $first = $this->finding();
        $this->actingAs($this->user())->post('/duplicates/'.$first->uuid.'/confirm');

        $response = $this->actingAs($this->user())->get('/duplicates?decision=PROPOSED');
        $body = $response->assertOk()->getContent();
        $this->assertIsString($body);

        $this->assertStringNotContainsString($first->uuid, $body);
    }

    #[Test]
    public function a_filter_the_queue_does_not_understand_is_refused(): void
    {
        $this->actingAs($this->user())
            ->getJson('/duplicates?decision=WHATEVER')
            ->assertStatus(422);
    }

    // ------------------------------------------------------------ fixtures

    private function finding(): DuplicateRelation
    {
        $this->asset('original.wav', 'the very same bytes');
        $second = $this->asset('copy.wav', 'the very same bytes');

        $relation = $this->app->make(EvaluateAssetDuplicates::class)->for($second)->first();
        $this->assertInstanceOf(DuplicateRelation::class, $relation, 'The fixture produced no finding.');

        return $relation;
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::query()->create([
            'name' => 'A reviewer',
            'email' => $role->value.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
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
