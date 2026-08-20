<?php

declare(strict_types=1);

namespace Tests\Feature\Transcription;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Catalog\Models\Track;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\Exceptions\UnknownTranscriptionProvider;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Models\TranscriptSegment;
use SaniTube\Transcription\Providers\NullTranscriptionProvider;
use SaniTube\Transcription\Services\TranscribeAsset;
use SaniTube\Transcription\Testing\FakeTranscriptionProvider;
use SaniTube\Transcription\TranscriptionManager;
use Tests\TestCase;

/**
 * TRN-001 — what a supplier heard, and what that is allowed to mean.
 *
 * A transcript is the weakest thing this platform stores: produced by a model
 * that was not trained on this catalogue, cannot be asked why, and is reliably
 * wrong about proper nouns and about singing. So the tests are mostly about
 * containment — that nothing here reaches the catalogue, that an absent
 * supplier is an ordinary state rather than a broken one, and that a file never
 * leaves as a URL.
 */
final class TranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private FakeTranscriptionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeTranscriptionProvider;

        $manager = $this->app->make(TranscriptionManager::class);
        $manager->register('fake', $this->provider);

        // Rebuilt so the default resolves to the fake: the manager reads its
        // default once, when it is constructed.
        $this->app->forgetInstance(TranscriptionManager::class);
        $this->app->instance(TranscriptionManager::class, $rebuilt = new TranscriptionManager([], 'fake'));
        $rebuilt->register('fake', $this->provider);
    }

    // -------------------------------------------------- the absent supplier

    #[Test]
    public function an_installation_with_no_supplier_transcribes_nothing_and_is_not_broken(): void
    {
        // The ordinary case, not the exceptional one. Most installations will
        // never configure a supplier, and the platform has to be whole without
        // one.
        $manager = new TranscriptionManager([], TranscriptionManager::DISABLED);

        $this->assertInstanceOf(NullTranscriptionProvider::class, $manager->default());
        $this->assertFalse($manager->isAvailable());
    }

    #[Test]
    public function the_disabled_supplier_refuses_rather_than_returning_an_empty_transcript(): void
    {
        // An empty string is a *result* -- the model heard nothing. Returning
        // one here would put a row in the table asserting this asset has no
        // speech in it, which nobody established.
        $this->expectException(TranscriptionFailed::class);

        (new NullTranscriptionProvider)->transcribe('/tmp/whatever.wav');
    }

    #[Test]
    public function an_unconfigured_supplier_is_an_error_and_a_blank_one_is_not(): void
    {
        // The asymmetry AI-001 learned the hard way. A fresh install, an unset
        // variable, a blank line and the literal `null` all mean "no
        // transcription here". A typo means somebody intended a supplier.
        foreach (['', '   ', null, 'null', 'NULL', false] as $blank) {
            $this->assertSame(
                TranscriptionManager::DISABLED,
                TranscriptionManager::normaliseProviderName($blank),
                'A blank configuration did not normalise to none.',
            );
        }

        $this->assertSame('whisper', TranscriptionManager::normaliseProviderName(' whisper '));

        $this->expectException(UnknownTranscriptionProvider::class);
        (new TranscriptionManager([], 'whispr'))->default();
    }

    #[Test]
    public function nothing_is_transcribed_when_the_supplier_is_unavailable(): void
    {
        $this->provider->unavailable()->willReturnText('should never be stored');

        $this->assertNull($this->transcribe($this->asset()));
        $this->assertSame(0, Transcript::query()->count());
        $this->assertSame(0, $this->provider->calls, 'An unavailable supplier was called anyway.');
    }

    // ------------------------------------------------------- what is stored

    #[Test]
    public function a_transcript_keeps_the_text_the_segments_and_how_it_was_produced(): void
    {
        $this->provider->willReturnText(
            'the first part and then the second',
            [[0, 1500, 'the first part'], [1500, 3200, 'and then the second']],
            language: 'en',
        );

        $transcript = $this->transcribe($this->asset(), languageHint: 'en');

        $this->assertInstanceOf(Transcript::class, $transcript);
        $this->assertSame('the first part and then the second', $transcript->text);
        $this->assertSame('en', $transcript->detected_language);
        $this->assertSame('fake', $transcript->provider);
        $this->assertSame('1', $transcript->provider_version);
        $this->assertSame('fake-model', $transcript->model);

        // Segments are rows, so "what is said around 2:14" is a WHERE clause.
        $this->assertSame([0, 1500], $transcript->segments->pluck('start_ms')->all());
        $this->assertSame(['the first part', 'and then the second'], $transcript->segments->pluck('text')->all());
    }

    #[Test]
    public function a_supplier_that_heard_nothing_leaves_no_row(): void
    {
        // An instrumental and a failed decode look identical from here, and
        // storing an empty transcript would state that this asset contains no
        // speech.
        $this->provider->willReturnText('   ');

        $this->assertNull($this->transcribe($this->asset()));
        $this->assertSame(0, Transcript::query()->count());
    }

    #[Test]
    public function a_failed_call_is_recorded_as_absent_rather_than_as_a_broken_asset(): void
    {
        $asset = $this->asset();
        $this->provider->failing();

        $this->assertNull($this->transcribe($asset));
        $this->assertSame(0, Transcript::query()->count());

        // The asset is untouched and still perfectly usable.
        $this->assertNotNull($asset->fresh());
    }

    #[Test]
    public function a_failed_call_leaves_no_temporary_file_behind(): void
    {
        // The case that fills a shared account's disk if nobody writes the
        // `finally`.
        $this->provider->failing();

        $before = count((array) glob(sys_get_temp_dir().'/sanitube-tr-*'));
        $this->transcribe($this->asset());

        $this->assertSame($before, count((array) glob(sys_get_temp_dir().'/sanitube-tr-*')));
    }

    // ------------------------------------------------------------ behaviour

    #[Test]
    public function the_supplier_receives_a_local_file_and_never_a_url(): void
    {
        // So no adapter can be talked into fetching an arbitrary address on the
        // platform's behalf.
        $this->provider->willReturnText('something');

        $this->transcribe($this->asset());

        $path = $this->provider->receivedPath;
        $this->assertIsString($path);
        $this->assertStringNotContainsString('://', $path, 'The supplier was handed a URL.');
        $this->assertStringStartsWith(sys_get_temp_dir(), $path);
    }

    #[Test]
    public function the_operators_hint_is_passed_through_and_kept_beside_the_guess(): void
    {
        // A hint is what a person believed; the guess is what a model heard.
        // The two differing is how a mislabelled import gets noticed, so both
        // are kept rather than one resolving the other.
        $this->provider->willReturnText('bonjour tout le monde', language: 'fr');

        $transcript = $this->transcribe($this->asset(), languageHint: 'en');

        $this->assertInstanceOf(Transcript::class, $transcript);
        $this->assertSame('en', $this->provider->receivedHint);
        $this->assertSame('en', $transcript->language_hint);
        $this->assertSame('fr', $transcript->detected_language);
        $this->assertTrue($transcript->contradictsTheHint());
    }

    #[Test]
    public function running_again_does_not_pay_for_the_call_twice(): void
    {
        $asset = $this->asset();
        $this->provider->willReturnText('once');

        $this->transcribe($asset);
        $this->transcribe($asset);
        $this->transcribe($asset);

        $this->assertSame(1, $this->provider->calls, 'The supplier was called more than once.');
        $this->assertSame(1, Transcript::query()->count());
    }

    #[Test]
    public function a_new_supplier_version_is_a_new_transcript_rather_than_a_replacement(): void
    {
        // So a later comparison has something to compare against.
        $asset = $this->asset();

        $this->provider->willReturnText('the old reading');
        $this->transcribe($asset);

        $this->provider->atVersion('2')->willReturnText('the new reading');
        $this->transcribe($asset);

        $this->assertSame(2, Transcript::query()->count());
        $this->assertSame(
            ['the old reading', 'the new reading'],
            Transcript::query()->orderBy('id')->pluck('text')->all(),
        );
    }

    #[Test]
    public function re_running_the_same_version_replaces_the_segments_rather_than_doubling_them(): void
    {
        // Segment boundaries move between runs, so matching old rows to new
        // ones is guesswork and a half-updated timeline is worse than either.
        $asset = $this->asset();

        $this->provider->willReturnText('a b', [[0, 100, 'a'], [100, 200, 'b']]);
        $this->transcribe($asset);

        $this->provider->willReturnText('a b c', [[0, 90, 'a'], [90, 180, 'b'], [180, 300, 'c']]);
        $this->transcribe($asset, force: true);

        $this->assertSame(1, Transcript::query()->count());
        $this->assertSame(3, TranscriptSegment::query()->count());
    }

    #[Test]
    public function nothing_a_supplier_said_reaches_the_catalogue(): void
    {
        // The containment that makes this safe to run unattended. A transcript
        // is a suggestion; it does not name a track, set its language, or touch
        // the asset it describes.
        $asset = $this->asset();
        $before = $asset->only(['original_filename', 'mime_type', 'sha256', 'status']);

        $this->provider->willReturnText('a title that is not a title', language: 'fr');
        $this->transcribe($asset, languageHint: 'en');

        $this->assertSame($before, $asset->fresh()?->only(['original_filename', 'mime_type', 'sha256', 'status']));
        $this->assertSame(0, Track::query()->count());
    }

    // ------------------------------------------------------------ fixtures

    private function transcribe(Asset $asset, ?string $languageHint = null, bool $force = false): ?Transcript
    {
        return $this->app->make(TranscribeAsset::class)->handle($asset, $languageHint, $force);
    }

    private function asset(): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'audio-'.bin2hex(random_bytes(8)));
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, 'master.wav'), $stream);
    }
}
