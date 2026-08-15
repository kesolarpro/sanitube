<?php

declare(strict_types=1);

namespace Tests\Unit\MusicGeneration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationStatus;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;

/**
 * Contract test for the generation provider abstraction.
 *
 * The point of these assertions is that they are written against
 * MusicGenerationProvider, not against the fake: when a real adapter arrives,
 * this file is the specification it must satisfy.
 */
final class FakeMusicGenerationProviderTest extends TestCase
{
    #[Test]
    public function generation_starts_asynchronously(): void
    {
        $provider = new FakeMusicGenerationProvider;

        $result = $provider->createGeneration(new GenerationRequest(prompt: 'warm analog house'));

        $this->assertSame(GenerationStatus::Pending, $result->status);
        $this->assertFalse($result->isTerminal());
        $this->assertNotSame('', $result->providerJobId);
        $this->assertNull($result->audioUrl);
    }

    #[Test]
    public function each_generation_gets_its_own_job_id(): void
    {
        $provider = new FakeMusicGenerationProvider;

        $first = $provider->createGeneration(new GenerationRequest(prompt: 'a'));
        $second = $provider->createGeneration(new GenerationRequest(prompt: 'b'));

        $this->assertNotSame($first->providerJobId, $second->providerJobId);
    }

    #[Test]
    public function a_completed_generation_exposes_downloadable_audio(): void
    {
        $provider = new FakeMusicGenerationProvider;
        $started = $provider->createGeneration(new GenerationRequest(prompt: 'lofi'));

        $provider->complete($started->providerJobId, 212);
        $result = $provider->getGenerationStatus($started->providerJobId);

        $this->assertTrue($result->isSuccessful());
        $this->assertTrue($result->hasDownloadableAudio());
        $this->assertSame(212, $result->durationSeconds);
    }

    #[Test]
    public function a_completed_generation_with_no_audio_is_not_downloadable(): void
    {
        // A provider that reports success without a file is a provider bug;
        // ingestion must not create an empty track from it.
        $provider = new FakeMusicGenerationProvider;
        $started = $provider->createGeneration(new GenerationRequest(prompt: 'lofi'));
        $provider->completeWithNothing($started->providerJobId);

        $this->assertFalse($provider->getGenerationStatus($started->providerJobId)->hasDownloadableAudio());
    }

    #[Test]
    public function a_failed_generation_carries_its_reason(): void
    {
        $provider = new FakeMusicGenerationProvider;
        $started = $provider->createGeneration(new GenerationRequest(prompt: 'lofi'));

        $provider->fail($started->providerJobId, 'quota exceeded');
        $result = $provider->getGenerationStatus($started->providerJobId);

        $this->assertTrue($result->isTerminal());
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('quota exceeded', $result->failureReason);
    }

    #[Test]
    public function a_pending_generation_can_be_cancelled_once(): void
    {
        $provider = new FakeMusicGenerationProvider;
        $started = $provider->createGeneration(new GenerationRequest(prompt: 'lofi'));

        $this->assertTrue($provider->cancelGeneration($started->providerJobId));
        $this->assertSame(GenerationStatus::Cancelled, $provider->getGenerationStatus($started->providerJobId)->status);
        $this->assertFalse($provider->cancelGeneration($started->providerJobId));
    }

    #[Test]
    public function an_unknown_job_fails_rather_than_throwing(): void
    {
        $provider = new FakeMusicGenerationProvider;

        $result = $provider->getGenerationStatus('never-existed');

        $this->assertSame(GenerationStatus::Failed, $result->status);
        $this->assertFalse($provider->cancelGeneration('never-existed'));
    }

    #[Test]
    public function an_unconfigured_provider_reports_itself_unavailable(): void
    {
        // No Suno API key is not an error state — it is the default state.
        $provider = new FakeMusicGenerationProvider(name: 'suno', available: false);

        $this->assertInstanceOf(MusicGenerationProvider::class, $provider);
        $this->assertFalse($provider->isAvailable());
        $this->assertSame('suno', $provider->name());
    }

    #[Test]
    public function an_instrumental_request_has_no_linguistic_content(): void
    {
        $request = new GenerationRequest(
            prompt: 'ambient pads',
            instrumental: true,
            language: ContentLanguage::fromCode('fr'),
        );

        // Instrumental wins over any language that was passed alongside it.
        $this->assertTrue($request->effectiveLanguage()->isInstrumental());
    }

    #[Test]
    public function a_vocal_request_without_a_language_is_undetermined_not_english(): void
    {
        $request = new GenerationRequest(prompt: 'a song');

        $this->assertTrue($request->effectiveLanguage()->isUnknown());
    }
}
