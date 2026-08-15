<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * The demonstration catalogue is exercised rather than merely shipped.
 *
 * A seeder that has drifted out of step with the invariants is worse than no
 * seeder: it is the first thing a new developer runs, and it fails in a way
 * that looks like the model is broken.
 */
final class DemoCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_produces_a_coherent_catalogue(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $this->assertGreaterThanOrEqual(2, Artist::query()->count());
        $this->assertGreaterThanOrEqual(1, Contributor::query()->count());
        $this->assertSame(3, Composition::query()->count());
        $this->assertSame(5, Track::query()->count());

        $this->assertSame(1, Release::query()->where('type', ReleaseType::Single)->count());
        $this->assertSame(1, Release::query()->where('type', ReleaseType::Ep)->count());
    }

    #[Test]
    public function every_seeded_release_actually_satisfies_the_readiness_invariants(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        foreach (Release::query()->get() as $release) {
            $this->assertSame(ReleaseStatus::Ready, $release->status);
            $this->assertSame([], $release->readinessProblems());
        }
    }

    #[Test]
    public function seeded_tracks_carry_exactly_one_active_isrc_each(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        foreach (Track::query()->get() as $track) {
            $this->assertSame(
                1,
                $track->externalIdentifiers()->whereNotNull('active_marker')->count(),
                sprintf('Track [%s] should carry exactly one active identifier.', $track->uuid),
            );
        }
    }

    #[Test]
    public function it_demonstrates_a_collaboration_with_two_primary_artists(): void
    {
        // The point of the seed data: a shape the model supports precisely
        // because there is no single-artist column forcing a choice.
        $this->seed(DemoCatalogSeeder::class);

        $collaborations = Track::query()->get()
            ->filter(fn (Track $track): bool => $track->primaryArtists()->count() > 1);

        $this->assertNotEmpty($collaborations);
    }

    #[Test]
    public function the_seeded_catalogue_names_no_provider(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $namespaces = ExternalIdentifier::query()->pluck('namespace')->unique()->all();

        // Seed data uses globally-issued identifiers only, so every namespace
        // is empty and no vendor is named anywhere in the demonstration set.
        $this->assertSame([''], array_values($namespaces));
    }
}
