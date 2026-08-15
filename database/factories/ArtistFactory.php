<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Enums\ArtistType;
use SaniTube\Artists\Models\Artist;

/**
 * @extends Factory<Artist>
 */
final class ArtistFactory extends Factory
{
    protected $model = Artist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => $name,
            'sort_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'type' => ArtistType::Person,
            'country' => 'FR',
            'primary_locale' => 'fr',
            'is_owner_controlled' => true,
            'status' => ArtistStatus::Active,
        ];
    }

    public function group(): self
    {
        return $this->state(['type' => ArtistType::Group]);
    }

    public function archived(): self
    {
        return $this->state(['status' => ArtistStatus::Archived]);
    }
}
