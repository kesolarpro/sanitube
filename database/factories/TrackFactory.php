<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Localization\ContentLanguage;

/**
 * @extends Factory<Track>
 */
final class TrackFactory extends Factory
{
    protected $model = Track::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->sentence(3),
            'language_code' => 'fr',
            'is_instrumental' => false,
            'is_explicit' => false,
            'duration_ms' => $this->faker->numberBetween(90_000, 320_000),
            'recording_year' => 2025,
            'status' => TrackStatus::Draft,
            'source' => TrackSource::Upload,
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => TrackStatus::Draft]);
    }

    public function instrumental(): self
    {
        // I8 keeps these two in step, so the state sets both rather than
        // leaving a factory that produces rows the domain would reject.
        return $this->state([
            'is_instrumental' => true,
            'language_code' => ContentLanguage::INSTRUMENTAL,
        ]);
    }

    public function legacyImport(): self
    {
        return $this->state(['source' => TrackSource::LegacyImport]);
    }

    /**
     * A track that genuinely satisfies I3: a verified master and a primary
     * artist, promoted through markReady() rather than by writing the status
     * directly. A factory that forced the status would produce tracks the
     * domain considers impossible, and tests built on them would prove
     * nothing.
     */
    public function ready(): self
    {
        return $this->afterCreating(function (Track $track): void {
            if ($track->master_asset_id === null) {
                $master = Asset::factory()->master()->verified()->create();
                $track->master_asset_id = $master->id;
                $track->save();
            }

            if ($track->primaryArtists()->count() === 0) {
                $track->artists()->attach(Artist::factory()->create()->id, [
                    'role' => TrackArtistRole::Primary->value,
                    'position' => 0,
                ]);
            }

            $track->refresh()->markReady();
        });
    }
}
