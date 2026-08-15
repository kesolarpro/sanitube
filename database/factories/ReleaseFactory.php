<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;

/**
 * @extends Factory<Release>
 */
final class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ReleaseType::Single,
            'title' => $this->faker->unique()->sentence(2),
            'language_code' => 'fr',
            'release_date' => now()->addMonth()->toDateString(),
            'status' => ReleaseStatus::Draft,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => ReleaseStatus::Draft]);
    }

    public function album(): self
    {
        return $this->state(['type' => ReleaseType::Album]);
    }

    /**
     * Builds everything I4 requires and then promotes through markReady(), so
     * the resulting release is one the domain would actually accept.
     */
    public function ready(int $trackCount = 1): self
    {
        return $this->afterCreating(function (Release $release) use ($trackCount): void {
            if ($release->cover_asset_id === null) {
                $release->cover_asset_id = Asset::factory()->artwork()->verified()->create()->id;
                $release->save();
            }

            if ($release->primaryArtists()->count() === 0) {
                $release->artists()->attach(Artist::factory()->create()->id, [
                    'role' => ReleaseArtistRole::Primary->value,
                    'position' => 0,
                ]);
            }

            $existing = $release->trackEntries()->count();

            for ($number = $existing + 1; $number <= max($trackCount, $existing); $number++) {
                $release->tracks()->attach(Track::factory()->ready()->create()->id, [
                    'disc_number' => 1,
                    'track_number' => $number,
                    'is_focus_track' => $number === 1,
                ]);
            }

            $release->refresh()->markReady();
        });
    }

    public function submitted(): self
    {
        return $this->ready()->afterCreating(function (Release $release): void {
            $release->status = ReleaseStatus::Submitted;
            $release->save();
        });
    }

    public function live(): self
    {
        return $this->ready()->afterCreating(function (Release $release): void {
            $release->status = ReleaseStatus::Live;
            $release->save();
        });
    }
}
