<?php

use App\Enums\EventStatus;
use App\Models\Client;
use App\Models\Event;
use App\Models\InvoiceLine;
use App\Models\Property;
use Database\Seeders\JurisdictionSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * A valid final-event submission, with any field overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventForm(Property $property, array $overrides = []): array
{
    return array_merge([
        'property_id' => $property->id,
        'recorded_at' => '2026-06-01',
        'status' => 'final',
        'noticed_value' => '500000.00',
        'final_value' => '420000.00',
    ], $overrides);
}

beforeEach(function () {
    $this->property = Property::factory()->for(Client::factory()->create(['external_id' => 'C9']))->create(['external_id' => 'P9']);
});

test('the form lists every property in a dropdown and the nav links to it', function () {
    $this->get(route('events.create'))
        ->assertOk()
        ->assertSee('<option value="'.$this->property->id.'"', false)
        ->assertSee('P9 (client C9)')
        ->assertSee('<option value="final"', false)
        ->assertSee('<option value="pending"', false);

    $this->get(route('clients.index'))->assertSee(route('events.create'));
});

test('a valid final event saves with a null source_id and redirects to the client page', function () {
    $this->post(route('events.store'), eventForm($this->property))
        ->assertRedirect(route('clients.show', 'C9'))
        ->assertSessionHas('status');

    $event = Event::sole();

    expect($event->source_id)->toBeNull()
        ->and($event->property_id)->toBe($this->property->id)
        ->and($event->status)->toBe(EventStatus::Final)
        ->and($event->recorded_at->toDateString())->toBe('2026-06-01')
        ->and($event->noticed_value)->toBe('500000.00')
        ->and($event->final_value)->toBe('420000.00');
});

test('a valid pending event saves with no final value', function () {
    $this->post(route('events.store'), eventForm($this->property, ['status' => 'pending', 'final_value' => '']))
        ->assertRedirect();

    $event = Event::sole();

    expect($event->status)->toBe(EventStatus::Pending)->and($event->final_value)->toBeNull();
});

test('two form events can both have a null source_id', function () {
    $this->post(route('events.store'), eventForm($this->property))->assertRedirect();
    $this->post(route('events.store'), eventForm($this->property, ['final_value' => '400000']))->assertRedirect();

    expect(Event::whereNull('source_id')->count())->toBe(2);
});

test('a final value above the noticed value is allowed', function () {
    $this->post(route('events.store'), eventForm($this->property, ['final_value' => '600000']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Event::sole()->final_value)->toBe('600000.00');
});

test('today is accepted as the recorded date', function () {
    $this->post(route('events.store'), eventForm($this->property, ['recorded_at' => now()->toDateString()]))
        ->assertSessionHasNoErrors();
});

test('each rule rejects bad input and saves nothing', function (array $overrides, string $errorField) {
    $this->from(route('events.create'))
        ->post(route('events.store'), eventForm($this->property, $overrides))
        ->assertRedirect(route('events.create'))
        ->assertSessionHasErrors($errorField);

    expect(Event::count())->toBe(0);
})->with([
    'missing property' => [['property_id' => ''], 'property_id'],
    'unknown property' => [['property_id' => 99999], 'property_id'],
    'missing date' => [['recorded_at' => ''], 'recorded_at'],
    'invalid date' => [['recorded_at' => '2026-02-30'], 'recorded_at'],
    'not a date' => [['recorded_at' => 'yesterday'], 'recorded_at'],
    'future date' => [['recorded_at' => now()->addDay()->toDateString()], 'recorded_at'],
    'missing status' => [['status' => ''], 'status'],
    'invalid status' => [['status' => 'approved'], 'status'],
    'missing noticed value' => [['noticed_value' => ''], 'noticed_value'],
    'non-numeric noticed value' => [['noticed_value' => 'abc'], 'noticed_value'],
    'negative noticed value' => [['noticed_value' => '-1'], 'noticed_value'],
    'three-decimal noticed value' => [['noticed_value' => '100.001'], 'noticed_value'],
    'noticed value too large for the column' => [['noticed_value' => '1000000000000'], 'noticed_value'],
    'final without a final value' => [['final_value' => ''], 'final_value'],
    'non-numeric final value' => [['final_value' => 'abc'], 'final_value'],
    'negative final value' => [['final_value' => '-5'], 'final_value'],
    'three-decimal final value' => [['final_value' => '100.005'], 'final_value'],
    'pending with a final value' => [['status' => 'pending', 'final_value' => '420000'], 'final_value'],
]);

test('the form shows the errors and refills the fields the user typed', function () {
    $this->followingRedirects()
        ->from(route('events.create'))
        ->post(route('events.store'), eventForm($this->property, ['noticed_value' => '100.001', 'final_value' => '777']))
        ->assertOk()
        ->assertSee('class="field-error"', false)
        ->assertSee('value="100.001"', false)
        ->assertSee('value="777"', false)
        ->assertSee('<option value="'.$this->property->id.'" selected>', false);
});

test('a revision submitted through the form is billed as an adjustment', function () {
    $this->seed(JurisdictionSeeder::class);
    Artisan::call('billing:import', ['path' => 'database/data/sample.json']);
    Artisan::call('billing:run', ['as_of' => '2026-07-01']);

    $p1 = Property::where('external_id', 'P1')->sole();

    $this->post(route('events.store'), eventForm($p1, [
        'recorded_at' => '2026-08-16',
        'noticed_value' => '400000',
        'final_value' => '340000',
    ]))->assertRedirect(route('clients.show', 'C1'));

    $formEvent = Event::whereNull('source_id')->sole();

    Artisan::call('billing:run', ['as_of' => '2026-09-01']);

    $adjustment = InvoiceLine::where('event_id', $formEvent->id)->sole();

    expect($adjustment->invoice->type->value)->toBe('adjustment')
        ->and(bccomp($adjustment->amount, '0', 2))->toBe(1)
        ->and($adjustment->adjusts_line_id)->not->toBeNull();

    $this->get(route('clients.show', 'C1'))->assertOk()->assertSee('Adjustment');
});

test('a pending event submitted through the form changes nothing until it is final', function () {
    $this->seed(JurisdictionSeeder::class);
    Artisan::call('billing:import', ['path' => 'database/data/sample.json']);
    Artisan::call('billing:run', ['as_of' => '2026-07-01']);

    $p1 = Property::where('external_id', 'P1')->sole();

    $this->post(route('events.store'), eventForm($p1, [
        'recorded_at' => '2026-08-16',
        'status' => 'pending',
        'final_value' => '',
    ]))->assertRedirect();

    $linesBefore = InvoiceLine::count();
    Artisan::call('billing:run', ['as_of' => '2026-09-01']);

    expect(InvoiceLine::count())->toBe($linesBefore);
});
