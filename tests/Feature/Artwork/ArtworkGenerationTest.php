<?php

declare(strict_types=1);

namespace Tests\Feature\Artwork;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Enums\ArtworkRefusal;
use SaniTube\Artwork\Exceptions\ArtworkGenerationException;
use SaniTube\Artwork\GeneratedImage;
use SaniTube\Artwork\ImageRequest;
use SaniTube\Artwork\ImageSize;
use SaniTube\Artwork\Jobs\MeasureArtworkJob;
use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Artwork\Providers\OpenAiImageProvider;
use SaniTube\Artwork\Services\ArtworkFeasibility;
use SaniTube\Artwork\Services\GenerateArtwork;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Artwork\Testing\FakeImageProvider;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * ART-002 — generated artwork, and the refusal that comes before the spending.
 *
 * Reading the published image specification turned up a mismatch that a
 * generator built without checking would have discovered by paying for it: the
 * documented square sizes of the image models available today are considerably
 * smaller than this platform's default artwork requirement of a 3000px shortest
 * side. Generate first and validate afterwards, and *every* cover is billed and
 * then rejected by the platform's own validator.
 *
 * So the headline test here is not that generation works. It is that when the
 * two configurations disagree, **nothing is sent at all** — asserted on the
 * provider's own record of what it was asked, not on the outcome.
 */
final class ArtworkGenerationTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // ------------------------------------------- refusing before spending

    #[Test]
    public function an_unreachable_requirement_refuses_without_asking_the_provider(): void
    {
        // The reason this ticket exists. 3000 required, 1024 available.
        config(['artwork.requirements.minimum_pixels' => 3000]);

        $provider = $this->provider(sizes: [ImageSize::square(1024)]);

        try {
            $this->app->make(GenerateArtwork::class)->handle('a moody synth cover');
            $this->fail('An unreachable requirement must refuse.');
        } catch (ArtworkGenerationException $exception) {
            $this->assertSame(ArtworkRefusal::RequirementsUnreachable, $exception->refusal);

            // The numbers that disagree, so a person can act rather than guess.
            $this->assertSame(3000, $exception->context['required_minimum_px']);
            $this->assertSame(1024, $exception->context['provider_largest_square_px']);
        }

        // The load-bearing assertion. Not "it failed" -- nothing was asked for.
        $this->assertSame([], $provider->requests);
    }

    #[Test]
    public function the_shipped_configuration_refuses_out_of_the_box(): void
    {
        // Deliberate, and stated in config/artwork.php rather than left as a
        // surprise: the default 3000px requirement and the one square size the
        // specification lists as supported across the GPT image models
        // genuinely disagree today.
        $shipped = require base_path('config/artwork.php');

        $minimum = $shipped['requirements']['minimum_pixels'];
        $sizes = $shipped['providers']['openai']['sizes'];

        $largest = 0;

        foreach ($sizes as $size) {
            $parsed = ImageSize::parse((string) $size);

            if ($parsed instanceof ImageSize && $parsed->isSquare()) {
                $largest = max($largest, $parsed->width);
            }
        }

        $this->assertGreaterThan(
            $largest,
            $minimum,
            'If these ever agree, the note in config/artwork.php explaining why they do not is wrong.',
        );
    }

    #[Test]
    public function an_installation_with_no_image_provider_says_so(): void
    {
        $this->provider(sizes: [ImageSize::square(1024)], available: false);

        try {
            $this->app->make(GenerateArtwork::class)->handle('anything');
            $this->fail('Generation must refuse without a provider.');
        } catch (ArtworkGenerationException $exception) {
            $this->assertSame(ArtworkRefusal::NoProvider, $exception->refusal);
        }
    }

    #[Test]
    public function an_empty_prompt_is_refused_before_anything_else(): void
    {
        $provider = $this->provider(sizes: [ImageSize::square(1024)]);
        config(['artwork.requirements.minimum_pixels' => 0]);

        foreach (['', '   ', "\n"] as $nothing) {
            try {
                $this->app->make(GenerateArtwork::class)->handle($nothing);
                $this->fail('An empty prompt must refuse.');
            } catch (ArtworkGenerationException $exception) {
                $this->assertSame(ArtworkRefusal::EmptyPrompt, $exception->refusal);
            }
        }

        $this->assertSame([], $provider->requests);
    }

    #[Test]
    public function a_provider_that_produces_nothing_usable_is_reported_as_such(): void
    {
        config(['artwork.requirements.minimum_pixels' => 0]);
        $this->provider(sizes: [ImageSize::square(1024)], image: null);

        try {
            $this->app->make(GenerateArtwork::class)->handle('a cover');
            $this->fail('A provider that returns nothing must refuse.');
        } catch (ArtworkGenerationException $exception) {
            $this->assertSame(ArtworkRefusal::ProviderFailed, $exception->refusal);
        }
    }

    // ------------------------------------------------------- choosing a size

    #[Test]
    public function the_largest_acceptable_size_is_the_one_requested(): void
    {
        // A cover scales down cleanly and up badly, so the biggest option that
        // still passes is the right default.
        config(['artwork.requirements.minimum_pixels' => 512]);

        $this->provider(sizes: [
            ImageSize::square(512),
            ImageSize::square(1536),
            ImageSize::square(1024),
        ]);

        $best = $this->app->make(ArtworkFeasibility::class)->bestUsableSize();

        $this->assertInstanceOf(ImageSize::class, $best);
        $this->assertSame(1536, $best->width);
    }

    #[Test]
    public function a_square_requirement_discards_the_oblong_sizes(): void
    {
        config(['artwork.requirements.minimum_pixels' => 1024, 'artwork.requirements.must_be_square' => true]);

        // 1792x1024 has a 1024 shortest side and would pass a bare minimum
        // check. It is not a cover.
        $this->provider(sizes: [new ImageSize(1792, 1024)]);

        $this->assertFalse($this->app->make(ArtworkFeasibility::class)->isFeasible());

        config(['artwork.requirements.must_be_square' => false]);
        $this->assertTrue($this->app->make(ArtworkFeasibility::class)->isFeasible());
    }

    #[Test]
    public function a_requirement_that_is_not_set_is_not_a_requirement(): void
    {
        config([
            'artwork.requirements.minimum_pixels' => 0,
            'artwork.requirements.maximum_pixels' => 0,
            'artwork.requirements.must_be_square' => false,
        ]);

        $this->provider(sizes: [new ImageSize(48, 16)]);

        $this->assertTrue($this->app->make(ArtworkFeasibility::class)->isFeasible());
    }

    #[Test]
    public function an_upper_bound_excludes_a_size_that_is_too_large(): void
    {
        config(['artwork.requirements.minimum_pixels' => 0, 'artwork.requirements.maximum_pixels' => 1024]);

        $this->provider(sizes: [ImageSize::square(2048)]);

        $this->assertFalse($this->app->make(ArtworkFeasibility::class)->isFeasible());
    }

    // --------------------------------------------------- the production path

    #[Test]
    public function a_generated_cover_becomes_an_ordinary_measured_artwork_asset(): void
    {
        // WHO CALLS THIS IN PRODUCTION: GenerateArtwork registers and stores
        // through the same service every upload uses, so the asset is
        // checksummed and verified like any other -- which is what makes
        // ART-001's listener measure it and the release validator judge it on
        // that measurement rather than on the provider's claim.
        config(['artwork.requirements.minimum_pixels' => 0]);

        $bytes = $this->png(1024, 1024);
        $this->provider(
            sizes: [ImageSize::square(1024)],
            image: new GeneratedImage($bytes, 'image/png', 'fake-image-1', 'a revised prompt'),
        );

        $asset = $this->app->make(GenerateArtwork::class)->handle('a moody synth cover');

        $this->assertSame(AssetKind::Artwork, $asset->kind);
        $this->assertSame(AssetStatus::Verified, $asset->status);
        $this->assertStringEndsWith('.png', (string) $asset->original_filename);

        // The wiring, not a manual call: reaching VERIFIED emitted
        // AssetVerified, ART-001's listener saw artwork, and the measurement
        // was queued. Without the verification step inside GenerateArtwork this
        // never fires and the cover is reported unmeasured for ever.
        Queue::assertPushed(
            MeasureArtworkJob::class,
            static fn (MeasureArtworkJob $job): bool => $job->assetUuid === $asset->uuid,
        );

        // And measured by measurement, never by the provider's word.
        $this->app->make(MeasureArtwork::class)->handle($asset->refresh());

        $analysis = ArtworkAnalysis::query()->where('asset_id', $asset->id)->firstOrFail();

        $this->assertTrue($analysis->succeeded);
        $this->assertSame(1024, $analysis->width_px);
    }

    #[Test]
    public function the_prompt_reaches_the_provider_with_the_chosen_size(): void
    {
        config(['artwork.requirements.minimum_pixels' => 1024]);

        $provider = $this->provider(
            sizes: [ImageSize::square(1024), ImageSize::square(512)],
            image: new GeneratedImage($this->png(8, 8), 'image/png'),
        );

        $this->app->make(GenerateArtwork::class)->handle('a moody synth cover');

        $this->assertCount(1, $provider->requests);
        $this->assertSame('a moody synth cover', $provider->requests[0]->prompt);
        $this->assertSame('1024x1024', (string) $provider->requests[0]->size);
    }

    // ------------------------------------------------------- the adapter

    #[Test]
    public function the_openai_adapter_sends_the_documented_request(): void
    {
        Http::fake(['*' => Http::response($this->imagesResponse($this->png(8, 8)))]);

        $provider = $this->openAi();
        $image = $provider->generate(new ImageRequest('a cover', ImageSize::square(1024)));

        $this->assertInstanceOf(GeneratedImage::class, $image);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertStringEndsWith('/images/generations', $request->url());
            $this->assertSame('gpt-image-1', $body['model']);
            $this->assertSame('a cover', $body['prompt']);
            $this->assertSame('1024x1024', $body['size']);
            $this->assertSame(1, $body['n']);
            $this->assertSame('png', $body['output_format']);

            // The specification says response_format applies to dall-e-2 and
            // dall-e-3, and that the GPT image models always return base64.
            // Sending it to one is sending a parameter it does not take.
            $this->assertArrayNotHasKey('response_format', $body);

            return true;
        });
    }

    #[Test]
    public function a_url_only_response_is_not_followed(): void
    {
        // A generated-image URL is valid for an hour: a bearer credential with
        // an expiry. No adapter here fetches an address a provider handed it,
        // and nothing downstream ever holds one.
        Http::fake(['*' => Http::response([
            'created' => 1,
            'data' => [['url' => 'https://files.example.test/generated.png?token=secret-value-1234']],
        ])]);

        $this->assertNull($this->openAi()->generate(new ImageRequest('a cover', ImageSize::square(1024))));
    }

    #[Test]
    public function a_failing_response_carries_no_body_anywhere(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'bad key sk-live-should-never-appear']],
            401,
        )]);

        $this->assertNull($this->openAi()->generate(new ImageRequest('a cover', ImageSize::square(1024))));
    }

    #[Test]
    public function the_status_decides_and_not_the_body(): void
    {
        // A gateway or cache can return a non-2xx carrying a perfectly
        // well-formed body -- a stale success, an echoed payload. Reading the
        // body and ignoring the status would hand back an image this request
        // did not generate.
        //
        // The same principle the distribution module already holds: an outage
        // is decided by code, never by what the message happens to contain.
        Http::fake(['*' => Http::response($this->imagesResponse($this->png(8, 8)), 502)]);

        $this->assertNull($this->openAi()->generate(new ImageRequest('a cover', ImageSize::square(1024))));
    }

    #[Test]
    public function the_adapter_reads_what_the_specification_returns(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1,
            'output_format' => 'jpeg',
            'model' => 'gpt-image-1',
            'data' => [[
                'b64_json' => base64_encode('bytes-here'),
                'revised_prompt' => 'a rewritten prompt',
            ]],
        ])]);

        $image = $this->openAi()->generate(new ImageRequest('a cover', ImageSize::square(1024)));

        $this->assertInstanceOf(GeneratedImage::class, $image);
        $this->assertSame('bytes-here', $image->bytes);
        $this->assertSame('image/jpeg', $image->mimeType);
        $this->assertSame('a rewritten prompt', $image->revisedPrompt);
    }

    #[Test]
    public function an_adapter_with_no_key_never_reaches_the_network(): void
    {
        Http::fake();

        $provider = new OpenAiImageProvider(['base_url' => 'https://api.example.test/v1', 'key' => null]);

        $this->assertFalse($provider->isAvailable());
        $this->assertNull($provider->generate(new ImageRequest('a cover', ImageSize::square(1024))));

        Http::assertNothingSent();
    }

    #[Test]
    public function capabilities_are_read_from_configuration_and_rubbish_is_dropped(): void
    {
        // Declared, never inferred from a model name. A mistyped setting is
        // reported as unusable rather than crashing a screen.
        $provider = new OpenAiImageProvider([
            'key' => 'k',
            'base_url' => 'https://api.example.test/v1',
            'sizes' => ['1024x1024', 'enormous', '', '1536x1024', 'x', 12],
        ]);

        $sizes = array_map(static fn (ImageSize $s): string => (string) $s, $provider->capabilities()->sizes);

        $this->assertSame(['1024x1024', '1536x1024'], $sizes);
        $this->assertSame(1024, $provider->capabilities()->largestSquare()?->width);
    }

    #[Test]
    public function the_container_resolves_the_provider_the_configuration_names(): void
    {
        // The binding, not the adapter. Everything above builds providers by
        // hand; nothing proved that a configured installation actually gets
        // one — which is the difference between an adapter that works and a
        // feature that exists.
        $this->app->forgetInstance(ImageProvider::class);
        config([
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.key' => 'k',
            'artwork.providers.openai.base_url' => 'https://api.example.test/v1',
        ]);

        $this->assertInstanceOf(OpenAiImageProvider::class, $this->app->make(ImageProvider::class));

        // Dispatched on the driver, so an operator may name the entry whatever
        // suits them without the platform deciding it does not recognise it.
        $this->app->forgetInstance(ImageProvider::class);
        config([
            'artwork.default_provider' => 'openai-eu',
            'artwork.providers.openai-eu' => [
                'driver' => 'openai',
                'key' => 'k',
                'base_url' => 'https://eu.example.test/v1',
            ],
        ]);

        $this->assertInstanceOf(OpenAiImageProvider::class, $this->app->make(ImageProvider::class));
    }

    #[Test]
    public function the_shipped_installation_has_no_image_provider(): void
    {
        $this->app->forgetInstance(ImageProvider::class);
        config(['artwork.default_provider' => 'none']);

        $provider = $this->app->make(ImageProvider::class);

        $this->assertFalse($provider->isAvailable());
        $this->assertTrue($provider->capabilities()->producesNothing());
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @param  list<ImageSize>  $sizes
     */
    private function provider(array $sizes, ?GeneratedImage $image = null, bool $available = true): FakeImageProvider
    {
        $provider = new FakeImageProvider($image, $sizes, $available);

        $this->app->instance(ImageProvider::class, $provider);

        return $provider;
    }

    private function openAi(): OpenAiImageProvider
    {
        return new OpenAiImageProvider([
            'key' => 'test-key-value',
            'base_url' => 'https://api.example.test/v1',
            'model' => 'gpt-image-1',
            'output_format' => 'png',
            'sizes' => ['1024x1024'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function imagesResponse(string $bytes): array
    {
        return [
            'created' => 1,
            'output_format' => 'png',
            'data' => [['b64_json' => base64_encode($bytes)]],
        ];
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
