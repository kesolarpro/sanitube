<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;

/**
 * @extends Factory<Asset>
 */
final class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => AssetKind::AudioMaster,
            'disk' => 'local',
            'path' => 'masters/'.$this->faker->uuid().'.wav',
            'original_filename' => $this->faker->word().'.wav',
            'mime_type' => 'audio/wav',
            'byte_size' => $this->faker->numberBetween(1_000_000, 80_000_000),
            'sha256' => hash('sha256', (string) $this->faker->unique()->uuid()),
            'duration_ms' => $this->faker->numberBetween(90_000, 320_000),
            'sample_rate' => 44_100,
            'bit_depth' => 16,
            'channels' => 2,
            'status' => AssetStatus::Pending,
        ];
    }

    public function master(): self
    {
        return $this->state(['kind' => AssetKind::AudioMaster, 'parent_asset_id' => null]);
    }

    public function artwork(): self
    {
        return $this->state([
            'kind' => AssetKind::Artwork,
            'mime_type' => 'image/jpeg',
            'path' => 'artwork/'.$this->faker->uuid().'.jpg',
            'duration_ms' => null,
            'sample_rate' => null,
            'bit_depth' => null,
            'channels' => null,
            'width' => 3000,
            'height' => 3000,
        ]);
    }

    public function stored(): self
    {
        return $this->state(['status' => AssetStatus::Stored, 'stored_at' => now()]);
    }

    /**
     * A verified asset is what the readiness invariants require, so this state
     * sets the timestamps a real verification would have written rather than
     * only flipping the status.
     */
    public function verified(): self
    {
        return $this->state([
            'status' => AssetStatus::Verified,
            'stored_at' => now()->subMinute(),
            'verified_at' => now(),
        ]);
    }

    public function derivativeOf(Asset $parent): self
    {
        return $this->state([
            'kind' => AssetKind::AudioDerivative,
            'parent_asset_id' => $parent->id,
            'mime_type' => 'audio/mpeg',
        ]);
    }
}
