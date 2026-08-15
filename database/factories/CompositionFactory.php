<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SaniTube\Catalog\Enums\CompositionStatus;
use SaniTube\Catalog\Models\Composition;

/**
 * @extends Factory<Composition>
 */
final class CompositionFactory extends Factory
{
    protected $model = Composition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->sentence(3),
            'language_code' => 'fr',
            'created_year' => 2024,
            'is_public_domain' => false,
            'status' => CompositionStatus::Draft,
        ];
    }

    public function registered(): self
    {
        return $this->state(['status' => CompositionStatus::Registered]);
    }
}
