<?php

use App\Enums\EventStatus;
use App\Enums\ExemptionType;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Jurisdiction;
use App\Models\Property;
use App\Models\Run;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

describe('relationships', function () {
    test('a property reaches its jurisdictions through property_jurisdictions', function () {
        $property = Property::factory()->create();
        $jurisdiction = Jurisdiction::factory()->create();

        $property->jurisdictions()->attach($jurisdiction);

        $this->assertDatabaseHas('property_jurisdictions', [
            'property_id' => $property->id,
            'jurisdiction_id' => $jurisdiction->id,
        ]);
        expect($property->jurisdictions->modelKeys())->toBe([$jurisdiction->id])
            ->and($jurisdiction->properties->modelKeys())->toBe([$property->id]);
    });

    test('a client reaches its properties and invoices', function () {
        $client = Client::factory()->create();
        $property = Property::factory()->for($client)->create();
        $invoice = Invoice::factory()->for($client)->create();

        expect($client->properties->modelKeys())->toBe([$property->id])
            ->and($client->invoices->modelKeys())->toBe([$invoice->id])
            ->and($property->client->is($client))->toBeTrue()
            ->and($invoice->client->is($client))->toBeTrue();
    });

    test('a run reaches its invoices', function () {
        $run = Run::factory()->create();
        $invoice = Invoice::factory()->for($run)->create();

        expect($run->invoices->modelKeys())->toBe([$invoice->id])
            ->and($invoice->run->is($run))->toBeTrue();
    });

    test('an invoice line connects its invoice, property and billed event', function () {
        $line = InvoiceLine::factory()->create();

        expect($line->invoice->lines->modelKeys())->toBe([$line->id])
            ->and($line->property->invoiceLines->modelKeys())->toBe([$line->id])
            ->and($line->event->invoiceLine->is($line))->toBeTrue()
            ->and($line->event->property->is($line->property))->toBeTrue()
            ->and($line->property->events->modelKeys())->toBe([$line->event_id])
            ->and($line->invoice->client->is($line->property->client))->toBeTrue();
    });

    test('an adjustment line points at the line it corrects', function () {
        $original = InvoiceLine::factory()->create();
        $adjustment = InvoiceLine::factory()->adjusting($original)->create();

        expect($adjustment->adjustedLine->is($original))->toBeTrue()
            ->and($original->adjustments->modelKeys())->toBe([$adjustment->id])
            ->and($original->adjusts_line_id)->toBeNull()
            ->and($adjustment->invoice->type)->toBe(InvoiceType::Adjustment)
            ->and($adjustment->property_id)->toBe($original->property_id);
    });
});

describe('casts', function () {
    test('stored money reads back as exact two-decimal strings', function () {
        $line = InvoiceLine::factory()->create([
            'noticed_value' => '999999999999.99',
            'final_value' => '420000.1',
            'savings' => '-1.84',
            'fee' => '69.38',
            'amount' => '-26.10',
        ])->fresh();

        expect($line->noticed_value)->toBe('999999999999.99')
            ->and($line->final_value)->toBe('420000.10')
            ->and($line->savings)->toBe('-1.84')
            ->and($line->fee)->toBe('69.38')
            ->and($line->amount)->toBe('-26.10');
    });

    test('an invoice total reads back as a string and can be negative', function () {
        $invoice = Invoice::factory()->adjustment()->create(['total' => '-26.10'])->fresh();

        expect($invoice->total)->toBe('-26.10');
    });

    test('event values read back as strings and a pending final value stays null', function () {
        $final = Event::factory()->create(['noticed_value' => '500000', 'final_value' => '420000.5'])->fresh();
        $pending = Event::factory()->pending()->create()->fresh();

        expect($final->noticed_value)->toBe('500000.00')
            ->and($final->final_value)->toBe('420000.50')
            ->and($final->status)->toBe(EventStatus::Final)
            ->and($pending->final_value)->toBeNull()
            ->and($pending->status)->toBe(EventStatus::Pending);
    });

    test('rates and exemption amounts keep four decimals', function () {
        $jurisdiction = Jurisdiction::factory()->create([
            'rate' => '0.95',
            'exemption_type' => ExemptionType::Percent,
            'exemption_amount' => '0.125',
        ])->fresh();
        $line = InvoiceLine::factory()->create(['fee_rate' => '0.25'])->fresh();

        expect($jurisdiction->rate)->toBe('0.9500')
            ->and($jurisdiction->exemption_amount)->toBe('0.1250')
            ->and($jurisdiction->exemption_type)->toBe(ExemptionType::Percent)
            ->and($line->fee_rate)->toBe('0.2500');
    });

    test('the breakdown keeps exact unrounded values as strings next to the rounded savings column', function () {
        $breakdown = [
            'exact_savings' => '1.84315500000000000000',
            'calculated_fee' => '0.55294650000000000000',
            'note' => 'waived',
            'jurisdictions' => [
                ['code' => 'ISD', 'rate' => '0.9500', 'tax_at_final' => '1330.00000000000000000000'],
            ],
        ];

        $line = InvoiceLine::factory()->create(['savings' => '1.84', 'breakdown' => $breakdown])->fresh();

        expect($line->breakdown)->toBe($breakdown)
            ->and($line->savings)->toBe('1.84');
    });

    test('dates read back as the recorded calendar date', function () {
        $event = Event::factory()->create(['recorded_at' => '2026-07-01'])->fresh();
        $run = Run::factory()->create(['as_of' => '2026-09-01'])->fresh();

        expect($event->recorded_at->toDateString())->toBe('2026-07-01')
            ->and($run->as_of->toDateString())->toBe('2026-09-01');
    });

    test('homestead reads back as a boolean', function () {
        expect(Property::factory()->homestead()->create()->fresh()->homestead)->toBeTrue()
            ->and(Property::factory()->create()->fresh()->homestead)->toBeFalse();
    });
});

describe('constraints', function () {
    test('rejects a duplicate of a unique key', function (Closure $createDuplicate) {
        expect($createDuplicate)->toThrow(UniqueConstraintViolationException::class);
    })->with([
        'client external_id' => function () {
            Client::factory()->count(2)->create(['external_id' => 'C1']);
        },
        'property external_id' => function () {
            Property::factory()->count(2)->create(['external_id' => 'P1']);
        },
        'jurisdiction code' => function () {
            Jurisdiction::factory()->count(2)->create(['code' => 'ISD']);
        },
        'event source_id' => function () {
            Event::factory()->count(2)->create(['source_id' => 'E1']);
        },
        'property and jurisdiction pair' => function () {
            $property = Property::factory()->create();
            $jurisdiction = Jurisdiction::factory()->create();
            $property->jurisdictions()->attach($jurisdiction);
            $property->jurisdictions()->attach($jurisdiction);
        },
        'invoice for the same run, client and type' => function () {
            $run = Run::factory()->create();
            $client = Client::factory()->create();
            Invoice::factory()->count(2)->for($run)->for($client)->create();
        },
        'event billed on a second line' => function () {
            $line = InvoiceLine::factory()->create();
            InvoiceLine::factory()->create([
                'property_id' => $line->property_id,
                'event_id' => $line->event_id,
            ]);
        },
    ]);

    test('allows one invoice and one adjustment for a client in the same run', function () {
        $run = Run::factory()->create();
        $client = Client::factory()->create();

        Invoice::factory()->for($run)->for($client)->create();
        Invoice::factory()->adjustment()->for($run)->for($client)->create();

        expect($client->invoices()->count())->toBe(2);
    });

    test('allows form events to share a null source_id', function () {
        Event::factory()->count(2)->create(['source_id' => null]);

        expect(Event::whereNull('source_id')->count())->toBe(2);
    });

    test('indexes foreign key columns that no composite or unique key leads with', function (string $table, string $column) {
        expect(Schema::hasIndex($table, [$column]))->toBeTrue();
    })->with([
        'properties.client_id' => ['properties', 'client_id'],
        'property_jurisdictions.jurisdiction_id' => ['property_jurisdictions', 'jurisdiction_id'],
        'invoices.client_id' => ['invoices', 'client_id'],
        'invoice_lines.invoice_id' => ['invoice_lines', 'invoice_id'],
        'invoice_lines.property_id' => ['invoice_lines', 'property_id'],
        'invoice_lines.adjusts_line_id' => ['invoice_lines', 'adjusts_line_id'],
    ]);

    test('foreign keys block deleting a record that others depend on', function (Closure $deleteParent) {
        expect($deleteParent)->toThrow(QueryException::class);
    })->with([
        'client with a property' => function () {
            Property::factory()->create()->client->delete();
        },
        'client with an invoice' => function () {
            Invoice::factory()->create()->client->delete();
        },
        'property with an event' => function () {
            Event::factory()->create()->property->delete();
        },
        'property with jurisdictions' => function () {
            $property = Property::factory()->create();
            $property->jurisdictions()->attach(Jurisdiction::factory()->create());
            $property->delete();
        },
        'jurisdiction in use' => function () {
            $jurisdiction = Jurisdiction::factory()->create();
            Property::factory()->create()->jurisdictions()->attach($jurisdiction);
            $jurisdiction->delete();
        },
        'run with an invoice' => function () {
            Invoice::factory()->create()->run->delete();
        },
        'event on a line, using a query that skips model events' => function () {
            InvoiceLine::factory()->create()->event()->delete();
        },
        'invoice with a line, using a query that skips model events' => function () {
            InvoiceLine::factory()->create()->invoice()->delete();
        },
        'original line of an adjustment, using a query that skips model events' => function () {
            InvoiceLine::factory()->adjusting(InvoiceLine::factory()->create())->create()->adjustedLine()->delete();
        },
    ]);
});

describe('immutability', function () {
    test('updating an issued record throws and changes nothing', function (string $model, string $column, string $original) {
        $record = $model::factory()->create([$column => $original]);

        expect(fn () => $record->update([$column => '1.00']))->toThrow(LogicException::class)
            ->and($record->fresh()->{$column})->toBe($original);
    })->with([
        'event' => [Event::class, 'noticed_value', '500000.00'],
        'invoice' => [Invoice::class, 'total', '212.88'],
        'invoice line' => [InvoiceLine::class, 'fee', '212.88'],
    ]);

    test('deleting an issued record throws and keeps it', function (string $model) {
        $record = $model::factory()->create();

        expect(fn () => $record->delete())->toThrow(LogicException::class);

        $this->assertModelExists($record);
    })->with([
        'event' => [Event::class],
        'invoice' => [Invoice::class],
        'invoice line' => [InvoiceLine::class],
    ]);
});
