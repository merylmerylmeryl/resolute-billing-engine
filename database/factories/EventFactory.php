<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state: a final event with no source_id, as from the form.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => null,
            'property_id' => Property::factory(),
            'recorded_at' => '2026-06-01',
            'status' => EventStatus::Final,
            'noticed_value' => '500000.00',
            'final_value' => '420000.00',
        ];
    }

    /**
     * Indicate that the outcome is still pending, so it has no final value.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Pending,
            'final_value' => null,
        ]);
    }
}
