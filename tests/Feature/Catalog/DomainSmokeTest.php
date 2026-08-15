<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

final class DomainSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_ready_track_can_be_built_through_the_factory(): void
    {
        $track = Track::factory()->ready()->create();

        $this->assertSame(TrackStatus::Ready, $track->refresh()->status);
        $this->assertSame(1, $track->primaryArtists()->count());
        $this->assertTrue($track->masterAsset()->first()?->isVerifiedMaster());
        $this->assertNotSame('', $track->uuid);
    }

    #[Test]
    public function a_ready_release_can_be_built_through_the_factory(): void
    {
        $release = Release::factory()->ready(3)->create();

        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);
        $this->assertSame(3, $release->tracks()->count());
        $this->assertSame(1, $release->primaryArtists()->count());
    }
}
