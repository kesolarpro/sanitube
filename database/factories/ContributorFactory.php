<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SaniTube\Contributors\Enums\ContributorType;
use SaniTube\Contributors\Models\Contributor;

/**
 * @extends Factory<Contributor>
 */
final class ContributorFactory extends Factory
{
    protected $model = Contributor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => $this->faker->name(),
            'display_name' => null,
            'type' => ContributorType::Person,
            'country' => 'FR',
        ];
    }

    public function organisation(): self
    {
        return $this->state([
            'type' => ContributorType::Organisation,
            'legal_name' => $this->faker->company(),
        ]);
    }
}
