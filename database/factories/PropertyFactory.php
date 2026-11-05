<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->bothify('P-#####'),
            'client_id' => Client::factory(),
            'homestead' => false,
        ];
    }

    /**
     * Indicate that the property is owner-occupied.
     */
    public function homestead(): static
    {
        return $this->state(fn (array $attributes) => [
            'homestead' => true,
        ]);
    }
}
