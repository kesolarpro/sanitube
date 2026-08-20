<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Ui\Queries\SuggestionReviewQuery;
use Tests\TestCase;

/**
 * ENR-005 — the screen a person answers suggestions on.
 *
 * **The four levels are the whole ticket.** ADR-0016 draws three lines and the
 * catalogue draws the fourth, and a reviewer who cannot see which is which will
 * accept a model's guess believing it was measured. So these tests are mostly
 * about the payload keeping them apart — and about the one column that must
 * stay empty when there is nothing true to put in it.
 */
final class SuggestionReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- the four levels

    #[Test]
    public function each_level_arrives_as_its_own_object(): void
    {
        // Named by the server, never assembled in the bundle from a flat row.
        // A component that re-derives which level a value belongs to is a
        // component that can drift from the domain.
        $track = $this->track();
        $this->analysis($track->master_asset_id);
        $transcript = $this->transcript($track->master_asset_id);
        $this->suggestion($track->master_asset_id, ['transcript_id' => $transcript->id]);

        $row = $this->firstRow();

        $this->assertSame('An older title', $row['canonical']['title']);
        $this->assertSame('en', $row['canonical']['language_code']);

        $this->assertSame(184000, $row['measured']['duration_ms']);
        $this->assertSame('flac', $row['measured']['codec']);

        $this->assertSame('fr', $row['proposed']['language']);
        $this->assertSame('whisper', $row['proposed']['provider']);

        $this->assertSame('Une chanson', $row['suggested']['title']);
        $this->assertSame(['Folk', 'Ambient'], $row['suggested']['genres']);
    }

    #[Test]
    public function what_a_model_suggested_never_appears_as_what_the_catalogue_says(): void
    {
        // The single mistake this screen exists to prevent. A reviewer reading
        // the model's title in the catalogue column would accept a guess
        // believing somebody had already put it there.
        $track = $this->track();
        $this->suggestion($track->master_asset_id);

        $row = $this->firstRow();

        $this->assertNotSame($row['suggested']['title'], $row['canonical']['title']);
        $this->assertNotSame($row['suggested']['language'], $row['canonical']['language_code']);
        $this->assertSame('An older title', $row['canonical']['title']);
    }

    #[Test]
    public function no_track_means_an_empty_catalogue_column_rather_than_a_filled_in_one(): void
    {
        // "Nothing yet" is the honest answer to "what does the catalogue say
        // about this recording", and null is how it is said. Filling the
        // column from the suggestion would be exactly the confusion the
        // separation exists to prevent.
        $this->suggestion($this->asset()->id);

        $row = $this->firstRow();

        $this->assertNull($row['canonical']);
        $this->assertFalse($row['actions']['has_track']);
        $this->assertSame('Une chanson', $row['suggested']['title']);
    }

    #[Test]
    public function a_measured_level_is_absent_rather_than_invented_when_nothing_was_analysed(): void
    {
        $this->suggestion($this->asset()->id);

        $row = $this->firstRow();

        $this->assertNull($row['measured']);
        $this->assertNull($row['proposed']);
    }

    #[Test]
    public function a_failed_analysis_is_not_read_as_a_measurement(): void
    {
        // An analysis row that failed carries whatever the analyser managed
        // before it stopped. Reading it would put a partial measurement on the
        // screen labelled as fact.
        $asset = $this->asset();
        $this->analysis($asset->id, succeeded: false);
        $this->suggestion($asset->id);

        $this->assertNull($this->firstRow()['measured']);
    }

    // ------------------------------------------------------------ provenance

    #[Test]
    public function the_row_says_how_the_suggestion_was_produced(): void
    {
        // A prompt version later found to be wrong is traceable through these
        // columns and nowhere else.
        $this->suggestion($this->asset()->id);

        $row = $this->firstRow();

        $this->assertSame('fake', $row['provider']);
        $this->assertSame('fake-1', $row['model']);
        $this->assertSame('v1', $row['prompt_version']);
        $this->assertSame(60.0, $row['confidence']);
    }

    #[Test]
    public function a_suggestion_made_from_evidence_that_has_changed_says_so_on_the_screen(): void
    {
        $asset = $this->asset();
        $transcript = $this->transcript($asset->id);
        $this->suggestion($asset->id, [
            'transcript_id' => $transcript->id,
            'evidence_version' => 'whisper:1',
        ]);

        $this->assertTrue($this->firstRow()['evidence_is_current']);

        $transcript->forceFill(['provider_version' => '2'])->save();

        $this->assertFalse($this->firstRow()['evidence_is_current']);
    }

    // ------------------------------------------------------------ containment

    #[Test]
    public function nothing_about_where_a_file_lives_reaches_the_screen(): void
    {
        // The same rule the asset list and the duplicate queue hold: a storage
        // layout published in page source is one that can no longer be
        // changed, and a durable link to a master is a leaked master.
        //
        // Asserted against the query's own output with unescaped slashes,
        // because DEDUP-003 found that reading rendered HTML hides a leaked
        // path -- Inertia escapes `/` as `\/` and the raw path never matches
        // whether or not it is there.
        $track = $this->track();
        $this->suggestion($track->master_asset_id);

        $asset = Asset::query()->firstOrFail();
        $payload = (string) json_encode($this->query()->paginate(), JSON_UNESCAPED_SLASHES);

        // Named columns, asserted individually. A loop that skipped anything
        // non-string would quietly check fewer secrets than it appears to --
        // which is exactly what happened when this was first written against a
        // property `Asset` does not have. Static analysis caught the typo; a
        // mutation caught that the test had stopped checking anything.
        $this->assertNotSame('', $asset->path);
        $this->assertNotSame('', $asset->disk);
        $this->assertNotSame('', $asset->original_filename);

        $this->assertStringNotContainsString($asset->path, $payload);
        $this->assertStringNotContainsString($asset->disk, $payload);
        $this->assertStringNotContainsString($asset->original_filename, $payload);
    }

    // ------------------------------------------------------------- the screen

    #[Test]
    public function anyone_signed_in_may_read_the_queue(): void
    {
        // Knowing what a model proposed about a recording is not a privilege.
        // Acting on it is, and those routes carry the guard.
        $track = $this->track();
        $this->suggestion($track->master_asset_id);

        $this->actingAs($this->user(UserRole::Member))
            ->get('/enrichment/suggestions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Enrichment/Index')
                ->has('page.rows', 1)
                ->where('page.rows.0.suggested.title', 'Une chanson')
                ->where('page.rows.0.canonical.title', 'An older title'));
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/enrichment/suggestions')->assertRedirect();
    }

    #[Test]
    public function the_queue_can_be_narrowed_to_what_is_still_waiting(): void
    {
        $track = $this->track();
        $this->suggestion($track->master_asset_id);
        $this->suggestion($track->master_asset_id, ['status' => SuggestionStatus::Rejected]);

        $this->actingAs($this->user())
            ->get('/enrichment/suggestions?status=PROPOSED')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('page.rows', 1)
                ->where('page.rows.0.status', 'PROPOSED'));
    }

    #[Test]
    public function a_status_that_is_not_a_state_is_refused_rather_than_ignored(): void
    {
        $this->actingAs($this->user())
            ->get('/enrichment/suggestions?status=NONSENSE')
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function the_queue_keeps_its_order_rather_than_promoting_confident_answers(): void
    {
        // A queue that reordered itself by confidence would teach a reviewer
        // that the rows near the bottom are the doubtful ones -- a claim no
        // model's self-reported confidence supports, and one that moves a row
        // out from under somebody working through a few hundred of them.
        $asset = $this->asset();

        $first = $this->suggestion($asset->id, [
            'suggested_title' => 'Earlier and unsure',
            'confidence_basis_points' => 1000,
            'suggested_at' => now()->subHour(),
        ]);
        $this->suggestion($asset->id, [
            'suggested_title' => 'Later and certain',
            'confidence_basis_points' => 10000,
        ]);

        $rows = $this->query()->paginate()['rows'];

        $this->assertSame($first->uuid, $rows[0]['uuid']);
    }

    #[Test]
    public function a_cursor_from_another_ordering_is_ignored_rather_than_followed(): void
    {
        // Following it would silently return the wrong page -- the failure
        // every cursor screen here guards against the same way.
        $track = $this->track();
        $this->suggestion($track->master_asset_id);

        $foreign = base64_encode((string) json_encode(['detected_at' => '2020-01-01', '_pointsToNextItems' => true]));

        $this->assertFalse(SuggestionReviewQuery::describesThisOrdering($foreign));
        $this->assertCount(1, $this->query()->paginate([], $foreign)['rows']);
    }

    // ------------------------------------------------------------ presentation

    #[Test]
    public function a_decided_suggestion_offers_nothing_to_decide(): void
    {
        // Presentation, and stated as such. The route middleware is the
        // permission check; a disabled button is a courtesy.
        $track = $this->track();
        $this->suggestion($track->master_asset_id, ['status' => SuggestionStatus::Accepted]);

        $row = $this->firstRow();

        $this->assertFalse($row['actions']['decidable']);
        $this->assertTrue($row['actions']['has_track']);
    }

    // ------------------------------------------------------------- fixtures

    private function query(): SuggestionReviewQuery
    {
        return $this->app->make(SuggestionReviewQuery::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRow(): array
    {
        $rows = $this->query()->paginate()['rows'];

        $this->assertNotEmpty($rows);

        return $rows[0];
    }

    private function track(): Track
    {
        return Track::factory()->create([
            'master_asset_id' => $this->asset()->id,
            'title' => 'An older title',
            'language_code' => 'en',
            'status' => TrackStatus::Draft,
        ]);
    }

    private function asset(): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, 'master.wav'), $stream);
    }

    private function analysis(?int $assetId, bool $succeeded = true): AudioAnalysis
    {
        return AudioAnalysis::query()->create([
            'asset_id' => $assetId,
            'analyzer' => 'ffprobe',
            'analyzer_version' => '1',
            'succeeded' => $succeeded,
            'duration_ms' => 184000,
            'codec' => 'flac',
            'channels' => 2,
            'sample_rate' => 44100,
            'analyzed_at' => now(),
        ]);
    }

    private function transcript(?int $assetId): Transcript
    {
        return Transcript::query()->create([
            'asset_id' => $assetId,
            'provider' => 'whisper',
            'provider_version' => '1',
            'detected_language' => 'fr',
            'text' => 'la la la',
            'covered_seconds' => 180,
            'transcribed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function suggestion(?int $assetId, array $overrides = []): MetadataSuggestion
    {
        $suggestion = MetadataSuggestion::query()->create([
            'asset_id' => $assetId,
            'task' => MetadataSuggestion::TASK_TRACK_METADATA,
            'provider' => 'fake',
            'model' => 'fake-1',
            'prompt_version' => 'v1',
            'suggested_title' => 'Une chanson',
            'suggested_language' => 'fr',
            'suggested_genres' => ['Folk', 'Ambient'],
            'suggested_moods' => ['calm'],
            'suggested_themes' => ['the sea'],
            'suggested_description' => 'A quiet folk piece.',
            'confidence_basis_points' => 6000,
            'status' => SuggestionStatus::Proposed,
            'suggested_at' => now(),
        ]);

        // Applied after creation rather than merged into the literal, so the
        // create() call keeps the typed attribute list static analysis can
        // check -- a merged array is `array<string, mixed>` and tells it
        // nothing.
        //
        // **Saved, not merely filled.** `forceFill` alone leaves the override
        // in memory and the database holding the original, so a fixture asking
        // for a REJECTED row hands back an object that says REJECTED and a
        // table that says PROPOSED. Every query-side assertion then reads the
        // wrong row while every in-memory one passes.
        if ($overrides !== []) {
            $suggestion->forceFill($overrides)->save();
        }

        return $suggestion;
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::query()->create([
            'name' => 'A reviewer',
            'email' => 's-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
