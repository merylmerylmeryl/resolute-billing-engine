<?php

namespace Database\Factories;

use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'client_id' => Client::factory(),
            'type' => InvoiceType::Invoice,
            'total' => '0.00',
        ];
    }

    /**
     * Indicate that the invoice is an adjustment.
     */
    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InvoiceType::Adjustment,
        ]);
    }
}
