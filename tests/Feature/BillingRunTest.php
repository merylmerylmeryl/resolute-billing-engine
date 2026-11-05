<?php

use App\Enums\ExemptionType;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Jurisdiction;
use App\Models\Property;
use App\Models\Run;
use Database\Seeders\JurisdictionSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(JurisdictionSeeder::class);
});

/**
 * A non-homestead property in one jurisdiction at $1.00 per $100, so savings are 1% of
 * the value reduction. With the default $500,000 noticed value and a 30% fee rate, a
 * final value of 400,000 gives $300.00, 390,000 gives $330.00 and 410,000 gives $270.00.
 */
function billableProperty(Client $client): Property
{
    $jurisdiction = Jurisdiction::firstOrCreate(
        ['code' => 'ONE'],
        ['rate' => '1.0000', 'exemption_type' => ExemptionType::None],
    );

    $property = Property::factory()->for($client)->create();
    $property->jurisdictions()->attach($jurisdiction);

    return $property;
}

function recordOutcome(Property $property, string $recordedAt, string $finalValue, string $noticedValue = '500000.00'): Event
{
    return Event::factory()->for($property)->create([
        'recorded_at' => $recordedAt,
        'noticed_value' => $noticedValue,
        'final_value' => $finalValue,
    ]);
}

function recordPending(Property $property, string $recordedAt): Event
{
    return Event::factory()->pending()->for($property)->create(['recorded_at' => $recordedAt]);
}

function importSampleData(): void
{
    expect(Artisan::call('billing:import', ['path' => 'database/data/sample.json']))->toBe(0);
}

/**
 * @param  array<string, mixed>  $data
 */
function importBillingData(array $data): void
{
    $path = tempnam(sys_get_temp_dir(), 'billing-run-');
    file_put_contents($path, json_encode($data));

    try {
        expect(Artisan::call('billing:import', ['path' => $path]))->toBe(0);
    } finally {
        unlink($path);
    }
}

function runBilling(string $asOf): void
{
    expect(Artisan::call('billing:run', ['as_of' => $asOf]))->toBe(0);
}

/**
 * @return array{runs: int, invoices: int, lines: int}
 */
function billingCounts(): array
{
    return ['runs' => Run::count(), 'invoices' => Invoice::count(), 'lines' => InvoiceLine::count()];
}

function lineForProperty(string $externalId): InvoiceLine
{
    return InvoiceLine::whereRelation('property', 'external_id', $externalId)->sole();
}

describe('the spec sample', function () {
    test('run 1 issues three invoices with the expected totals', function () {
        importSampleData();

        $this->artisan('billing:run', ['as_of' => '2026-07-01'])
            ->expectsOutputToContain('C1 invoice 212.88')
            ->expectsOutputToContain('C2 invoice 2569.38')
            ->expectsOutputToContain('C3 invoice 0.00')
            ->assertSuccessful();

        $run = Run::sole();
        $invoices = Invoice::with('client')->get();

        expect($run->as_of->toDateString())->toBe('2026-07-01')
            ->and($invoices->pluck('total', 'client.external_id')->all())->toBe(['C1' => '212.88', 'C2' => '2569.38', 'C3' => '0.00'])
            ->and($invoices->pluck('type')->unique()->all())->toBe([InvoiceType::Invoice])
            ->and($invoices->pluck('run_id')->unique()->all())->toBe([$run->id]);
    });

    test('run 1 bills the expected line for each property', function (string $property, string $savings, string $rate, string $calculatedFee, string $fee, ?string $note) {
        importSampleData();

        runBilling('2026-07-01');

        $line = lineForProperty($property);

        expect($line->savings)->toBe($savings)
            ->and($line->fee_rate)->toBe($rate)
            ->and($line->fee)->toBe($fee)
            ->and($line->amount)->toBe($fee)
            ->and($line->adjusts_line_id)->toBeNull()
            ->and($line->breakdown['calculated_fee'])->toBe($calculatedFee)
            ->and($line->breakdown['note'])->toBe($note);
    })->with([
        'P1' => ['P1', '709.60', '0.3000', '212.88', '212.88', null],
        'P2 rounds half up' => ['P2', '277.50', '0.2500', '69.38', '69.38', null],
        'P3 is capped' => ['P3', '10640.00', '0.2500', '2660.00', '2500.00', 'capped'],
        'P4 has no savings' => ['P4', '0.00', '0.2500', '0.00', '0.00', 'no_savings'],
        'P5 is waived' => ['P5', '25.08', '0.3000', '7.52', '0.00', 'waived'],
    ]);

    test('run 1 skips the pending property and the event after the as-of date', function () {
        importSampleData();

        runBilling('2026-07-01');

        expect(InvoiceLine::count())->toBe(5)
            ->and(InvoiceLine::whereRelation('property', 'external_id', 'P6')->exists())->toBeFalse()
            ->and(Event::where('source_id', 'E7')->sole()->invoiceLine)->toBeNull();
    });

    test('a line stores a snapshot that explains the charge', function () {
        importSampleData();

        runBilling('2026-07-01');

        $line = lineForProperty('P1');
        $jurisdictions = collect($line->breakdown['jurisdictions'])->keyBy('code');

        expect($line->event->source_id)->toBe('E1')
            ->and($line->noticed_value)->toBe('400000.00')
            ->and($line->final_value)->toBe('360000.00')
            ->and(bcadd($line->breakdown['exact_savings'], '0', 2))->toBe('709.60')
            ->and(bcadd($jurisdictions['ISD']['savings'], '0', 2))->toBe('380.00')
            ->and(bcadd($jurisdictions['CNTY']['savings'], '0', 2))->toBe('121.60')
            ->and(bcadd($jurisdictions['CITY']['savings'], '0', 2))->toBe('208.00')
            ->and($jurisdictions['ISD']['exemption_type'])->toBe('flat');
    });

    test('run 2 issues one adjustment for C1 and leaves the original unchanged', function () {
        importSampleData();
        runBilling('2026-07-01');
        $originalLine = lineForProperty('P1');
        $originalInvoice = $originalLine->invoice;
        $countsAfterFirstRun = billingCounts();

        $this->artisan('billing:run', ['as_of' => '2026-09-01'])
            ->expectsOutputToContain('C1 adjustment 53.22')
            ->assertSuccessful();

        $adjustment = Invoice::where('type', InvoiceType::Adjustment)->sole();
        $adjustmentLine = $adjustment->lines()->sole();

        expect(billingCounts())->toBe(['runs' => 2, 'invoices' => $countsAfterFirstRun['invoices'] + 1, 'lines' => $countsAfterFirstRun['lines'] + 1])
            ->and($adjustment->client->external_id)->toBe('C1')
            ->and($adjustment->total)->toBe('53.22')
            ->and($adjustmentLine->fee)->toBe('266.10')
            ->and($adjustmentLine->amount)->toBe('53.22')
            ->and($adjustmentLine->savings)->toBe('887.00')
            ->and($adjustmentLine->fee_rate)->toBe('0.3000')
            ->and($adjustmentLine->adjusts_line_id)->toBe($originalLine->id)
            ->and($adjustmentLine->event->source_id)->toBe('E7')
            ->and($originalLine->fresh()->getAttributes())->toEqual($originalLine->getAttributes())
            ->and($originalInvoice->fresh()->getAttributes())->toEqual($originalInvoice->getAttributes());
    });
});

describe('re-running', function () {
    test('a re-run creates nothing new', function (string $asOf) {
        importSampleData();
        runBilling('2026-07-01');
        runBilling('2026-09-01');
        $counts = billingCounts();

        $this->artisan('billing:run', ['as_of' => $asOf])
            ->expectsOutputToContain('Nothing new to bill.')
            ->assertSuccessful();

        expect(billingCounts())->toBe($counts);
    })->with([
        'with the same as-of date' => '2026-09-01',
        'with an older as-of date' => '2026-07-01',
    ]);

    test('a run that issues nothing creates no run row', function () {
        importSampleData();

        runBilling('2026-01-01');

        expect(billingCounts())->toBe(['runs' => 0, 'invoices' => 0, 'lines' => 0]);
    });

    test('a failure part way through a run issues nothing', function () {
        recordOutcome(billableProperty(Client::factory()->create()), '2026-06-01', '400000.00');
        Event::factory()->for(Property::factory()->for(Client::factory()))->create(['recorded_at' => '2026-06-01']);

        expect(fn () => runBilling('2026-07-01'))->toThrow(InvalidArgumentException::class);

        expect(billingCounts())->toBe(['runs' => 0, 'invoices' => 0, 'lines' => 0]);
    });

    test('rejects an as-of date that is not a real Y-m-d date', function (string $asOf) {
        importSampleData();

        $this->artisan('billing:run', ['as_of' => $asOf])
            ->expectsOutputToContain('as-of date must be a real date in Y-m-d format')
            ->assertFailed();

        expect(billingCounts())->toBe(['runs' => 0, 'invoices' => 0, 'lines' => 0]);
    })->with([
        'empty' => '',
        'blank' => '   ',
        'unpadded' => '2026-7-1',
        'wrong order' => '07/01/2026',
        'an impossible day' => '2026-02-31',
        'not a date' => 'yesterday',
        'with a time' => '2026-07-01 00:00:00',
    ]);
});

describe('events', function () {
    test('counts an event recorded exactly on the as-of date but not the next day', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-07-01', '400000.00');

        runBilling('2026-06-30');
        $countsBeforeTheDate = billingCounts();
        runBilling('2026-07-01');

        expect($countsBeforeTheDate['lines'])->toBe(0)
            ->and($property->invoiceLines()->sole()->fee)->toBe('300.00');
    });

    test('bills the event with the highest id when two share a date', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '390000.00');
        $recordedLast = recordOutcome($property, '2026-06-01', '400000.00');

        runBilling('2026-07-01');

        $line = $property->invoiceLines()->sole();

        expect($line->event_id)->toBe($recordedLast->id)
            ->and($line->fee)->toBe('300.00');
    });

    test('bills only the latest of two unbilled final events', function () {
        $property = billableProperty(Client::factory()->create());
        $latest = recordOutcome($property, '2026-06-15', '400000.00');
        recordOutcome($property, '2026-06-01', '390000.00');

        runBilling('2026-07-01');

        $line = $property->invoiceLines()->sole();

        expect($line->event_id)->toBe($latest->id)
            ->and($line->fee)->toBe('300.00')
            ->and($line->invoice->type)->toBe(InvoiceType::Invoice);
    });

    test('bills a pending property in a later run once it is final', function () {
        $property = billableProperty(Client::factory()->create());
        recordPending($property, '2026-06-01');
        recordOutcome($property, '2026-08-01', '400000.00');

        runBilling('2026-07-01');
        $countsWhilePending = billingCounts();
        runBilling('2026-09-01');

        expect($countsWhilePending)->toBe(['runs' => 0, 'invoices' => 0, 'lines' => 0])
            ->and($property->invoiceLines()->sole()->fee)->toBe('300.00');
    });

    test('keeps the bill when a final outcome goes back to pending, then adjusts once final again', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        runBilling('2026-06-30');
        recordPending($property, '2026-07-15');

        runBilling('2026-07-31');
        $countsWhilePending = billingCounts();
        recordOutcome($property, '2026-08-15', '390000.00');
        runBilling('2026-08-31');

        $adjustmentLine = $property->invoiceLines()->orderByDesc('id')->first();

        expect($countsWhilePending)->toBe(['runs' => 1, 'invoices' => 1, 'lines' => 1])
            ->and(InvoiceLine::count())->toBe(2)
            ->and($adjustmentLine->amount)->toBe('30.00')
            ->and($adjustmentLine->fee)->toBe('330.00');
    });

    test('does not bill an earlier final event when the latest event is pending', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        recordPending($property, '2026-06-15');

        runBilling('2026-07-01');

        expect(billingCounts())->toBe(['runs' => 0, 'invoices' => 0, 'lines' => 0]);
    });

    test('does not adjust for a newer final event when the latest event is pending', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        runBilling('2026-06-30');
        recordOutcome($property, '2026-07-01', '390000.00');
        recordPending($property, '2026-07-15');

        runBilling('2026-08-01');

        expect(billingCounts())->toBe(['runs' => 1, 'invoices' => 1, 'lines' => 1]);
    });

    test('adjusts for a revision recorded on the same date as the billed event', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        $revision = recordOutcome($property, '2026-06-01', '390000.00');

        runBilling('2026-07-01');

        expect($revision->invoiceLine->fee)->toBe('330.00')
            ->and($revision->invoiceLine->amount)->toBe('30.00');
    });

    test('a backdated event changes nothing', function (string $asOf) {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-15', '400000.00');
        runBilling('2026-06-30');
        recordOutcome($property, '2026-06-01', '390000.00');

        runBilling($asOf);

        expect(billingCounts())->toBe(['runs' => 1, 'invoices' => 1, 'lines' => 1]);
    })->with([
        'with a later as-of date, where the billed event is still latest' => '2026-09-01',
        'with an older as-of date, where the backdated event is the only eligible one' => '2026-06-10',
    ]);
});

describe('fee tier', function () {
    test('applies 25% to a client with two final properties and one pending', function () {
        $client = Client::factory()->create();
        $first = billableProperty($client);
        $second = billableProperty($client);
        $pending = billableProperty($client);
        recordOutcome($first, '2026-06-01', '400000.00');
        recordOutcome($second, '2026-06-01', '400000.00');
        recordPending($pending, '2026-06-01');

        runBilling('2026-07-01');

        $invoice = Invoice::sole();

        expect($invoice->total)->toBe('500.00')
            ->and($first->invoiceLines()->sole()->fee_rate)->toBe('0.2500')
            ->and($second->invoiceLines()->sole()->fee_rate)->toBe('0.2500')
            ->and($pending->invoiceLines()->exists())->toBeFalse();
    });

    test('moves to 25% only once a third property is enrolled, without re-rating earlier lines', function () {
        $client = Client::factory()->create();
        $first = billableProperty($client);
        $second = billableProperty($client);
        $third = billableProperty($client);
        billableProperty($client);
        recordOutcome($first, '2026-06-01', '400000.00');
        recordOutcome($second, '2026-06-01', '400000.00');
        recordOutcome($third, '2026-08-01', '400000.00');
        recordOutcome($first, '2026-08-01', '390000.00');

        runBilling('2026-07-01');
        $earlierLine = $second->invoiceLines()->sole();
        runBilling('2026-09-01');

        $newLine = $third->invoiceLines()->sole();
        $adjustmentLine = $first->invoiceLines()->whereNotNull('adjusts_line_id')->sole();

        expect($earlierLine->fee_rate)->toBe('0.3000')
            ->and($earlierLine->fee)->toBe('300.00')
            ->and($second->invoiceLines()->count())->toBe(1)
            ->and($newLine->fee_rate)->toBe('0.2500')
            ->and($newLine->fee)->toBe('250.00')
            ->and($adjustmentLine->fee_rate)->toBe('0.3000')
            ->and($adjustmentLine->fee)->toBe('330.00')
            ->and($adjustmentLine->amount)->toBe('30.00');
    });
});

describe('adjustments', function () {
    test('issues a credit when the fee goes down', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        recordOutcome($property, '2026-08-01', '410000.00');

        runBilling('2026-09-01');

        $adjustment = Invoice::where('type', InvoiceType::Adjustment)->sole();

        expect($adjustment->total)->toBe('-30.00')
            ->and($adjustment->lines()->sole()->fee)->toBe('270.00')
            ->and($adjustment->lines()->sole()->amount)->toBe('-30.00');
    });

    test('measures a second revision against the latest fee and points at the original line', function () {
        importSampleData();
        runBilling('2026-07-01');
        $originalLine = lineForProperty('P1');
        runBilling('2026-09-01');
        recordOutcome(Property::where('external_id', 'P1')->sole(), '2026-10-01', '354904.17', '400000.00');

        runBilling('2026-11-01');

        $secondAdjustmentLine = InvoiceLine::orderByDesc('id')->first();

        expect($secondAdjustmentLine->fee)->toBe('240.00')
            ->and($secondAdjustmentLine->amount)->toBe('-26.10')
            ->and($secondAdjustmentLine->adjusts_line_id)->toBe($originalLine->id);
    });

    test('uses the latest event\'s noticed value when it differs from the billed one', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        $revision = recordOutcome($property, '2026-08-01', '400000.00', '600000.00');

        runBilling('2026-09-01');

        expect($revision->invoiceLine->noticed_value)->toBe('600000.00')
            ->and($revision->invoiceLine->fee)->toBe('600.00')
            ->and($revision->invoiceLine->amount)->toBe('300.00');
    });

    test('charges the full fee when a waived property is revised above the waiver', function () {
        $property = billableProperty(Client::factory()->create());
        $waivedLineEvent = recordOutcome($property, '2026-06-01', '499000.00');
        runBilling('2026-07-01');
        $waivedLine = $waivedLineEvent->invoiceLine;
        recordOutcome($property, '2026-08-01', '400000.00');

        runBilling('2026-09-01');

        $adjustmentLine = InvoiceLine::where('adjusts_line_id', $waivedLine->id)->sole();

        expect($waivedLine->fee)->toBe('0.00')
            ->and($waivedLine->breakdown['note'])->toBe('waived')
            ->and($adjustmentLine->fee)->toBe('300.00')
            ->and($adjustmentLine->amount)->toBe('300.00');
    });

    test('issues nothing for a revision that does not change the fee', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '1000000.00', '2000000.00');
        runBilling('2026-07-01');
        $counts = billingCounts();
        $revision = recordOutcome($property, '2026-08-01', '900000.00', '2000000.00');

        runBilling('2026-09-01');
        runBilling('2026-10-01');

        expect(billingCounts())->toBe($counts)
            ->and($revision->invoiceLine)->toBeNull();
    });

    test('a re-import that changes property data does not adjust a line that is already billed', function () {
        importSampleData();
        runBilling('2026-09-01');
        $counts = billingCounts();

        importBillingData([
            'properties' => [['id' => 'P1', 'client_id' => 'C1', 'homestead' => false, 'jurisdictions' => ['ISD', 'CNTY', 'CITY']]],
            'events' => [],
        ]);
        runBilling('2026-10-01');

        expect(billingCounts())->toBe($counts);
    });

    test('adjusts once when a re-import changes the fee of a revision that never reached a line', function () {
        $data = [
            'properties' => [['id' => 'P1', 'client_id' => 'C1', 'homestead' => true, 'jurisdictions' => ['ISD', 'CNTY', 'CITY']]],
            'events' => [
                ['source_id' => 'E1', 'property_id' => 'P1', 'recorded_at' => '2026-06-01', 'status' => 'final', 'noticed_value' => 400000, 'final_value' => 360000],
                ['source_id' => 'E2', 'property_id' => 'P1', 'recorded_at' => '2026-08-01', 'status' => 'final', 'noticed_value' => 400000, 'final_value' => 360000],
            ],
        ];
        importBillingData($data);
        runBilling('2026-07-01');
        runBilling('2026-09-01');
        $countsBeforeReimport = billingCounts();
        $data['properties'][0]['homestead'] = false;

        importBillingData($data);
        runBilling('2026-10-01');
        $countsAfterAdjustment = billingCounts();
        runBilling('2026-11-01');

        $adjustmentLine = Invoice::where('type', InvoiceType::Adjustment)->sole()->lines()->sole();

        expect($countsBeforeReimport)->toBe(['runs' => 1, 'invoices' => 1, 'lines' => 1])
            ->and($adjustmentLine->event->source_id)->toBe('E2')
            ->and($adjustmentLine->fee)->toBe('222.00')
            ->and($adjustmentLine->amount)->toBe('9.12')
            ->and($countsAfterAdjustment)->toBe(['runs' => 2, 'invoices' => 2, 'lines' => 2])
            ->and(billingCounts())->toBe($countsAfterAdjustment);
    });

    test('puts several revisions on one adjustment with one line each, and the total can be negative', function () {
        $client = Client::factory()->create();
        $first = billableProperty($client);
        $second = billableProperty($client);
        recordOutcome($first, '2026-06-01', '400000.00');
        recordOutcome($second, '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        recordOutcome($first, '2026-08-01', '410000.00');
        recordOutcome($second, '2026-08-01', '395000.00');

        runBilling('2026-09-01');

        $adjustment = Invoice::where('type', InvoiceType::Adjustment)->sole();

        expect($adjustment->total)->toBe('-15.00')
            ->and($adjustment->lines()->count())->toBe(2)
            ->and($adjustment->lines()->where('property_id', $first->id)->sole()->amount)->toBe('-30.00')
            ->and($adjustment->lines()->where('property_id', $second->id)->sole()->amount)->toBe('15.00');
    });

    test('issues one invoice and one adjustment to a client with new and revised properties in one run', function () {
        $client = Client::factory()->create();
        $revised = billableProperty($client);
        $new = billableProperty($client);
        recordOutcome($revised, '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        recordOutcome($revised, '2026-08-01', '390000.00');
        recordOutcome($new, '2026-08-01', '400000.00');

        runBilling('2026-09-01');

        $run = Run::orderByDesc('id')->first();
        $invoice = $run->invoices()->where('type', InvoiceType::Invoice)->sole();
        $adjustment = $run->invoices()->where('type', InvoiceType::Adjustment)->sole();

        expect($run->invoices()->count())->toBe(2)
            ->and($invoice->total)->toBe('300.00')
            ->and($invoice->lines()->sole()->property_id)->toBe($new->id)
            ->and($adjustment->total)->toBe('30.00')
            ->and($adjustment->lines()->sole()->property_id)->toBe($revised->id);
    });
});

describe('seasons', function () {
    test('bills a second-year final event as a new charge with its own tier count', function () {
        $client = Client::factory()->create();
        $returning = billableProperty($client);
        recordOutcome($returning, '2026-06-01', '400000.00');
        recordOutcome(billableProperty($client), '2026-06-01', '400000.00');
        recordOutcome(billableProperty($client), '2026-06-01', '400000.00');
        runBilling('2026-07-01');
        recordOutcome($returning, '2027-06-01', '400000.00');

        runBilling('2027-07-01');

        $secondYearLine = $returning->invoiceLines()->orderByDesc('id')->first();

        expect($returning->invoiceLines()->orderBy('id')->first()->fee_rate)->toBe('0.2500')
            ->and($secondYearLine->fee_rate)->toBe('0.3000')
            ->and($secondYearLine->fee)->toBe('300.00')
            ->and($secondYearLine->adjusts_line_id)->toBeNull()
            ->and($secondYearLine->invoice->type)->toBe(InvoiceType::Invoice);
    });

    test('puts unbilled events from two seasons on two lines of one invoice', function () {
        $property = billableProperty(Client::factory()->create());
        $firstSeason = recordOutcome($property, '2026-06-01', '400000.00');
        $secondSeason = recordOutcome($property, '2027-06-01', '390000.00');

        runBilling('2027-07-01');

        $invoice = Invoice::sole();

        expect($invoice->total)->toBe('630.00')
            ->and($invoice->lines()->pluck('event_id')->sort()->values()->all())->toBe([$firstSeason->id, $secondSeason->id]);
    });

    test('applies the cap in each season', function () {
        $property = billableProperty(Client::factory()->create());
        recordOutcome($property, '2026-06-01', '1000000.00', '2000000.00');
        recordOutcome($property, '2027-06-01', '1000000.00', '2000000.00');

        runBilling('2027-07-01');

        expect(Invoice::sole()->total)->toBe('5000.00')
            ->and($property->invoiceLines()->pluck('fee')->all())->toBe(['2500.00', '2500.00']);
    });

    test('a revision adjusts the line from its own season', function () {
        $property = billableProperty(Client::factory()->create());
        $firstSeason = recordOutcome($property, '2026-06-01', '400000.00');
        recordOutcome($property, '2027-06-01', '410000.00');
        runBilling('2027-07-01');
        recordOutcome($property, '2026-12-01', '390000.00');

        runBilling('2027-08-01');

        $adjustmentLine = Invoice::where('type', InvoiceType::Adjustment)->sole()->lines()->sole();

        expect($adjustmentLine->adjusts_line_id)->toBe($firstSeason->invoiceLine->id)
            ->and($adjustmentLine->amount)->toBe('30.00');
    });
});
