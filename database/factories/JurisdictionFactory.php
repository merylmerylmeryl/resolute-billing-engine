<?php

namespace Database\Factories;

use App\Enums\ExemptionType;
use App\Models\Jurisdiction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Jurisdiction>
 */
class JurisdictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('J????'),
            'rate' => '0.5000',
            'exemption_type' => ExemptionType::None,
            'exemption_amount' => null,
        ];
    }
}
