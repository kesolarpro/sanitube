<?php

declare(strict_types=1);

namespace Tests\Feature\Artwork;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\ImageSize;
use SaniTube\Artwork\Testing\FakeImageProvider;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * ART-003 — telling an operator whether a cover could be generated here.
 *
 * `ArtworkFeasibility` has known this since ART-002 and had **no caller**, so
 * somebody who configured an image provider and expected covers got nothing and
 * no explanation. The information existed; it was not on a screen.
 *
 * **The contradiction is stated, never worked around.** ART-002 found that the
 * documented square sizes of the image models available today are considerably
 * smaller than this platform's default requirement of a 3000px shortest side.
 * Both are settings, so an operator has two honest ways out — and this reports
 * the two numbers so they can choose one. "Generation unavailable" alone leaves
 * somebody re-reading provider documentation for an afternoon.
 *
 * **Nothing here spends anything.** It compares two configurations, which is
 * why it can be asked on every render of a release screen.
 */
final class ArtworkGenerationReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fresh_installation_says_covers_are_attached_by_hand(): void
    {
        // The ordinary state, and not a fault. A release with a hand-uploaded
        // cover is complete; nothing is missing because no provider is set.
        $this->withProvider(new FakeImageProvider(available: false));

        $readiness = $this->readiness();

        $this->assertFalse($readiness['available']);
        $this->assertSame('NO_PROVIDER', $readiness['refusal']);
    }

    #[Test]
    public function a_provider_that_cannot_reach_the_requirement_is_refused_before_anything_is_sent(): void
    {
        // The ART-002 contradiction, on a screen. Nothing has been sent, so
        // nothing has been spent — which is the whole reason feasibility is
        // asked before generation rather than after it.
        config(['artwork.requirements.minimum_pixels' => 3000, 'artwork.requirements.square' => true]);

        $this->withProvider(new FakeImageProvider(sizes: [new ImageSize(1024, 1024)]));

        $readiness = $this->readiness();

        $this->assertFalse($readiness['available']);
        $this->assertSame('REQUIREMENTS_UNREACHABLE', $readiness['refusal']);
    }

    #[Test]
    public function the_refusal_names_the_two_numbers_that_disagree(): void
    {
        // Without them the refusal is a dead end. With them it names two
        // settings, either of which an operator can change.
        config(['artwork.requirements.minimum_pixels' => 3000, 'artwork.requirements.square' => true]);

        $this->withProvider(new FakeImageProvider(sizes: [new ImageSize(1024, 1024)]));

        $detail = $this->readiness()['detail'];

        $this->assertSame('3000', $detail['required_minimum_px']);
        $this->assertSame('1024', $detail['provider_largest_square_px']);
        $this->assertSame('fake', $detail['provider']);
    }

    #[Test]
    public function a_capable_provider_is_reported_with_the_size_it_would_use(): void
    {
        config(['artwork.requirements.minimum_pixels' => 1000, 'artwork.requirements.square' => true]);

        $this->withProvider(new FakeImageProvider(sizes: [
            new ImageSize(1024, 1024),
            // The largest that still passes: a cover scales down cleanly and up
            // badly, so the biggest acceptable option is the right default.
            new ImageSize(2048, 2048),
        ]));

        $readiness = $this->readiness();

        $this->assertTrue($readiness['available']);
        $this->assertNull($readiness['refusal']);
        $this->assertSame('2048×2048', $readiness['detail']['size']);
    }

    #[Test]
    public function an_installation_with_no_requirement_accepts_whatever_the_provider_makes(): void
    {
        // A requirement that is not set is not a requirement.
        config(['artwork.requirements.minimum_pixels' => null, 'artwork.requirements.square' => false]);

        $this->withProvider(new FakeImageProvider(sizes: [new ImageSize(256, 256)]));

        $this->assertTrue($this->readiness()['available']);
    }

    #[Test]
    public function nothing_is_ever_sent_to_the_provider_to_answer_this(): void
    {
        // The reason this can be on every render of a release screen. It
        // compares two settings; it does not ask a paid service anything.
        config(['artwork.requirements.minimum_pixels' => 3000]);

        $provider = new FakeImageProvider(sizes: [new ImageSize(1024, 1024)]);
        $this->withProvider($provider);

        $this->readiness();

        $this->assertSame([], $provider->requests);
    }

    #[Test]
    public function a_null_figure_is_left_out_rather_than_shown_as_a_dash(): void
    {
        // A screen that prints "required minimum: —" is describing a
        // requirement nobody set as though it were part of the disagreement.
        config(['artwork.requirements.minimum_pixels' => 3000, 'artwork.requirements.square' => true]);

        // No square sizes at all, so `provider_largest_square_px` is null.
        $this->withProvider(new FakeImageProvider(sizes: [new ImageSize(1792, 1024)]));

        $this->assertArrayNotHasKey('provider_largest_square_px', $this->readiness()['detail']);
    }

    // ----------------------------------------------------------- fixtures

    private function withProvider(FakeImageProvider $provider): void
    {
        $this->app->instance(ImageProvider::class, $provider);
    }

    /**
     * Read from the release screen, not from the service.
     *
     * **PRODUCTION PATH.** The whole defect being closed is a service with no
     * caller, so a test that asked the service directly would be reproducing
     * the defect rather than proving it fixed.
     *
     * @return array<string, mixed>
     */
    private function readiness(): array
    {
        $release = Release::factory()->create();

        $page = $this->actingAs($this->user())->get('/releases/'.$release->uuid)->assertOk();

        /** @var array<string, mixed> $readiness */
        $readiness = $page->viewData('page')['props']['release']['artwork_generation'];

        return $readiness;
    }

    private function user(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
