<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\SuggestTitle;
use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\EmbeddedTags;
use SaniTube\Media\Jobs\AnalyzeAudioAssetJob;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\ApplyEmbeddedTags;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;
use SaniTube\Media\Testing\FakeAudioAnalyzer;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * TAG-001 — what the file says about itself.
 *
 * `ffprobe -show_format` has always returned `format.tags`, and SaniTube has
 * always stored the whole probe payload in `audio_analyses.raw` and then read
 * six numbers out of it. **The tags were already in the database, already paid
 * for, and never looked at.** For nine hundred files that is the difference
 * between typing nine hundred titles and correcting the few that are wrong.
 *
 * Two claims carry the ticket:
 *
 *   - **A tag is evidence, not truth.** Files acquire tags from whatever last
 *     touched them. Everything is recorded for a reviewer to see; nothing is
 *     treated as decided.
 *   - **A tag never overwrites a person.** The suggested title is improved only
 *     while it is still the machine's own guess.
 */
final class EmbeddedTagsTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // ---------------------------------------------------------- the reading

    #[Test]
    public function it_reads_the_fields_worth_trusting_from_a_probe_payload(): void
    {
        $tags = EmbeddedTags::fromProbe($this->probe([
            'title' => 'Ma chanson',
            'artist' => 'Une artiste',
            'album' => 'Un album',
            'album_artist' => 'Une autre',
            'genre' => 'Chanson',
            'track' => '3/12',
            'date' => '2019-04-01',
        ]));

        $this->assertSame('Ma chanson', $tags->title);
        $this->assertSame('Une artiste', $tags->artist);
        $this->assertSame('Un album', $tags->album);
        $this->assertSame('Une autre', $tags->albumArtist);
        $this->assertSame('Chanson', $tags->genre);

        // "3/12" is a track number and a total. The total is not a track
        // number and is discarded rather than parsed into one.
        $this->assertSame(3, $tags->trackNumber);

        // A full ISO date, from which only the year is a recording year.
        $this->assertSame(2019, $tags->year);
    }

    #[Test]
    public function tag_names_are_read_whatever_case_the_format_wrote_them_in(): void
    {
        // The same tag arrives as TITLE from a FLAC, title from an MP3 and
        // Title from something that got there via iTunes.
        $tags = EmbeddedTags::fromProbe($this->probe(['TITLE' => 'Ma chanson', 'Artist' => 'Une artiste']));

        $this->assertSame('Ma chanson', $tags->title);
        $this->assertSame('Une artiste', $tags->artist);
    }

    #[Test]
    public function a_vorbis_albumartist_is_the_same_field_as_ffprobes_album_artist(): void
    {
        $this->assertSame(
            'Une artiste',
            EmbeddedTags::fromProbe($this->probe(['albumartist' => 'Une artiste']))->albumArtist,
        );
    }

    #[Test]
    public function anything_unreadable_is_simply_absent(): void
    {
        // This runs over files nobody curated. A tag that could not be read is
        // indistinguishable from a tag that was never there, and both mean
        // "ask a person".
        /** @var list<array<string, mixed>> $probes */
        $probes = [
            [],
            ['format' => 'not an array'],
            ['format' => ['tags' => 'not an array']],
            ['format' => ['tags' => ['title' => '', 'artist' => '   ']]],
            ['format' => ['tags' => ['title' => ['nested']]]],
        ];

        foreach ($probes as $probe) {
            $this->assertTrue(EmbeddedTags::fromProbe($probe)->isEmpty());
        }
    }

    #[Test]
    public function a_control_character_never_survives_into_a_tag(): void
    {
        // A tag ends up in a page, in a log and eventually in a delivery, and
        // a newline in any of those is somebody else's parsing problem.
        $tags = EmbeddedTags::fromProbe($this->probe(['title' => "Ma\nchanson\x00"]));

        $this->assertSame('Machanson', $tags->title);
    }

    #[Test]
    public function a_year_a_corrupt_frame_produced_is_not_a_year(): void
    {
        foreach (['0000', '9999', 'not a date'] as $nonsense) {
            $this->assertNull(EmbeddedTags::fromProbe($this->probe(['date' => $nonsense]))->year);
        }

        $this->assertSame(1974, EmbeddedTags::fromProbe($this->probe(['date' => '1974']))->year);
    }

    #[Test]
    public function only_the_fields_that_are_there_are_offered_to_a_reviewer(): void
    {
        // A file with two tags must not present five empty rows as though
        // something had been looked for and not found.
        $this->assertSame(
            ['title' => 'Ma chanson', 'artist' => 'Une artiste'],
            EmbeddedTags::fromProbe($this->probe(['title' => 'Ma chanson', 'artist' => 'Une artiste']))->toArray(),
        );
    }

    // -------------------------------------------------------- what it writes

    #[Test]
    public function a_tag_title_improves_the_guess_made_from_the_filename(): void
    {
        // WHO CALLS THIS IN PRODUCTION: AnalyzeAudioAssetJob, right after the
        // analyser has stored the probe payload, and the console command that
        // does the same work for a catalogue brought in by terminal.
        $candidate = $this->candidate('01_-_final_MASTER_v3.wav');

        $this->assertSame('final MASTER v3', $candidate->suggested_title, 'The filename guess, for contrast.');

        $this->apply($candidate, ['title' => 'Ma chanson']);

        $this->assertSame('Ma chanson', $candidate->refresh()->suggested_title);
    }

    #[Test]
    public function a_tag_never_overwrites_a_title_a_person_chose(): void
    {
        // The whole ticket. `suggested_title` is filled from a tag only while
        // it is still the machine's own guess — and `SuggestTitle` is
        // deterministic, so a title equal to its output is one nobody touched.
        $candidate = $this->candidate('01_-_final_MASTER_v3.wav');

        $candidate->forceFill(['suggested_title' => 'Le vrai titre'])->save();

        $this->apply($candidate, ['title' => 'Whatever The Ripper Said']);

        $this->assertSame('Le vrai titre', $candidate->refresh()->suggested_title);
    }

    #[Test]
    public function everything_the_file_claims_is_recorded_even_when_the_title_is_protected(): void
    {
        // Recording and applying are different acts. A reviewer still needs to
        // see what the file said, precisely so they can see it disagree.
        $candidate = $this->candidate('master.wav');
        $candidate->forceFill(['suggested_title' => 'Le vrai titre'])->save();

        $this->apply($candidate, ['title' => 'Something Else', 'album' => 'Un album']);

        $tags = $candidate->refresh()->metadata[ApplyEmbeddedTags::METADATA_KEY] ?? null;

        $this->assertSame('Something Else', $tags['title'] ?? null);
        $this->assertSame('Un album', $tags['album'] ?? null);
    }

    #[Test]
    public function applying_twice_reaches_the_same_answer(): void
    {
        // Re-analysing a file re-reads the same tags. After the first pass the
        // title no longer matches the filename guess, which protects it from
        // this service as well — so the second pass changes nothing.
        $candidate = $this->candidate('01_-_final_MASTER_v3.wav');

        $this->apply($candidate, ['title' => 'Ma chanson']);
        $this->apply($candidate->refresh(), ['title' => 'Ma chanson']);

        $this->assertSame('Ma chanson', $candidate->refresh()->suggested_title);
    }

    #[Test]
    public function a_file_with_no_tags_leaves_the_candidate_exactly_as_it_was(): void
    {
        $candidate = $this->candidate('master.wav');
        $before = $candidate->suggested_title;

        $this->apply($candidate, []);

        $this->assertSame($before, $candidate->refresh()->suggested_title);
        $this->assertArrayNotHasKey(ApplyEmbeddedTags::METADATA_KEY, $candidate->metadata ?? []);
    }

    #[Test]
    public function a_candidate_with_no_analysis_is_left_alone(): void
    {
        $candidate = $this->candidate('master.wav');
        $before = $candidate->suggested_title;

        $this->app->make(ApplyEmbeddedTags::class)->handle($candidate, null);

        $this->assertSame($before, $candidate->refresh()->suggested_title);
    }

    #[Test]
    public function a_manifest_claim_is_not_trampled_by_a_tag(): void
    {
        // A manifest title is something an operator typed deliberately, which
        // is exactly the kind of answer this must not overwrite. It lands in
        // `suggested_title` like any other, and the filename comparison is
        // what protects it.
        $candidate = $this->candidate('master.wav');
        $candidate->forceFill([
            'suggested_title' => 'Titre du manifeste',
            'metadata' => ['suggested_title_source' => 'manifest'],
        ])->save();

        $this->apply($candidate, ['title' => 'Tag Title']);

        $this->assertSame('Titre du manifeste', $candidate->refresh()->suggested_title);
        $this->assertSame('manifest', $candidate->metadata['suggested_title_source'] ?? null);
    }

    // --------------------------------------------------- the production path

    #[Test]
    public function the_analysis_job_reads_the_tags_without_being_asked_to(): void
    {
        // **PRODUCTION PATH.** Every other test here calls the service
        // directly, and TAG-001's mutation pass showed exactly what that
        // misses: deleting the call from `AnalyzeAudioAssetJob` broke none of
        // them. A service nothing invokes is the pattern this whole phase of
        // work exists to remove, and a test suite that cannot see the
        // difference is how one gets shipped.
        $analyzer = new FakeAudioAnalyzer;

        $analyzer->willReturn(AudioAnalysisResult::measured(
            durationMs: 180_000,
            codec: 'pcm_s16le',
            container: 'wav',
            sampleRate: 44_100,
            bitDepth: 16,
            channels: 2,
            bitrate: 1_411_000,
            raw: $this->probe(['title' => 'Ma chanson', 'album' => 'Un album']),
        ));

        $this->app->instance(AudioAnalyzer::class, $analyzer);

        $candidate = $this->candidate('01_-_final_MASTER_v3.wav');

        (new AnalyzeAudioAssetJob($candidate->uuid))->handle(
            $this->app->make(AnalyzeAsset::class),
            $this->app->make(SettleCandidateAfterAnalysis::class),
            $this->app->make(ApplyEmbeddedTags::class),
        );

        $candidate->refresh();

        $this->assertSame('Ma chanson', $candidate->suggested_title);
        $this->assertSame(
            ['title' => 'Ma chanson', 'album' => 'Un album'],
            $candidate->metadata[ApplyEmbeddedTags::METADATA_KEY] ?? null,
        );
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_review_screen_shows_what_the_file_claimed(): void
    {
        $candidate = $this->candidate('master.wav');
        $this->apply($candidate, ['title' => 'Ma chanson', 'album' => 'Un album']);

        $page = $this->actingAs($this->reviewer())
            ->get('/ingestion/candidates/'.$candidate->uuid)
            ->assertOk();

        /** @var array<string, mixed> $detail */
        $detail = $page->viewData('page')['props']['candidate'];

        $this->assertSame(['title' => 'Ma chanson', 'album' => 'Un album'], $detail['embedded_tags']);
    }

    #[Test]
    public function a_candidate_whose_file_claims_nothing_shows_no_section(): void
    {
        // Null rather than an empty list, so the screen leaves the section out
        // rather than heading an empty one.
        $candidate = $this->candidate('master.wav');

        $page = $this->actingAs($this->reviewer())
            ->get('/ingestion/candidates/'.$candidate->uuid)
            ->assertOk();

        $this->assertNull($page->viewData('page')['props']['candidate']['embedded_tags']);
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @param  array<string, mixed>  $tags
     * @return array<string, mixed>
     */
    private function probe(array $tags): array
    {
        return ['format' => ['tags' => $tags]];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function apply(TrackCandidate $candidate, array $tags): void
    {
        // Updated rather than inserted, because there is one analysis row per
        // (asset, analyzer, version) and a re-analysis replaces it. Creating a
        // second would violate the unique index — and would also be a fixture
        // describing something the platform never does.
        $analysis = AudioAnalysis::query()->updateOrCreate(
            ['asset_id' => $candidate->asset_id, 'analyzer' => 'ffprobe', 'analyzer_version' => '1'],
            ['succeeded' => true, 'raw' => $tags === [] ? ['format' => []] : $this->probe($tags)],
        );

        $this->app->make(ApplyEmbeddedTags::class)->handle($candidate, $analysis);
    }

    private function candidate(string $filename): TrackCandidate
    {
        $assets = $this->app->make(AssetStorageService::class);

        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        /** @var Asset $asset */
        $asset = $assets->store($assets->register(AssetKind::AudioMaster, $filename), $stream);

        // Verified, because `AnalyzeAsset` refuses to analyse anything else —
        // an unverified master is bytes nobody has confirmed, and measuring
        // them would be measuring something that may not be what arrived.
        $asset = $this->app->make(AssetVerificationService::class)->verify($asset->refresh());

        return TrackCandidate::query()->create([
            'source' => IngestionSource::ManualUpload,
            'asset_id' => $asset->id,
            'original_filename' => $filename,
            'suggested_title' => SuggestTitle::from($filename),
            'status' => TrackCandidateStatus::Pending,
        ]);
    }

    private function reviewer(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
    }
}
