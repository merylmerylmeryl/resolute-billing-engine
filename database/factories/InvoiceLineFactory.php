<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * Define the model's default state. The invoice and event belong to the same
     * client and property as the line.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->state([
                'client_id' => Property::findOrFail($attributes['property_id'])->client_id,
            ]),
            'event_id' => fn (array $attributes) => Event::factory()->state([
                'property_id' => $attributes['property_id'],
            ]),
            'adjusts_line_id' => null,
            'noticed_value' => '500000.00',
            'final_value' => '420000.00',
            'savings' => '0.00',
            'fee_rate' => '0.3000',
            'fee' => '0.00',
            'amount' => '0.00',
            'breakdown' => [],
        ];
    }

    /**
     * Indicate that the line adjusts the given line, on a new adjustment invoice.
     */
    public function adjusting(InvoiceLine $original): static
    {
        return $this->state(fn (array $attributes) => [
            'property_id' => $original->property_id,
            'invoice_id' => Invoice::factory()->adjustment()->state([
                'client_id' => $original->invoice->client_id,
            ]),
            'adjusts_line_id' => $original->id,
        ]);
    }
}
