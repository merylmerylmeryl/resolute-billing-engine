<?php

use App\Models\Client;
use App\Models\Invoice;
use Database\Seeders\JurisdictionSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(JurisdictionSeeder::class);
    Artisan::call('billing:import', ['path' => 'database/data/sample.json']);
    Artisan::call('billing:run', ['as_of' => '2026-07-01']);
});

test('the home page redirects to the client list', function () {
    $this->get('/')->assertRedirect(route('clients.index'));
});

test('the client list shows every client with its property and invoice counts', function () {
    $this->get(route('clients.index'))
        ->assertOk()
        ->assertSeeInOrder(['C1', 'C2', 'C3'])
        ->assertSee(route('clients.show', 'C2'));
});

test('the client list counts invoices and adjustments separately', function () {
    Artisan::call('billing:run', ['as_of' => '2026-09-01']);

    $counts = $this->get(route('clients.index'))
        ->assertOk()
        ->assertSeeInOrder(['Properties', 'Invoices', 'Adjustments'])
        ->viewData('clients')
        ->mapWithKeys(fn ($client) => [$client->external_id => [$client->properties_count, $client->invoices_count, $client->adjustments_count]])
        ->all();

    expect($counts)->toBe(['C1' => [1, 1, 1], 'C2' => [3, 1, 0], 'C3' => [2, 1, 0]]);
});

test('a 25% rate is labelled with the property count that earned it, a 30% rate is not', function () {
    $this->get('/clients/C2')->assertOk()->assertSee('25%')->assertSee('3+ properties');
    $this->get('/clients/C1')->assertOk()->assertSee('30%')->assertDontSee('3+ properties');
});

test('exemptions are described in words and jurisdiction rates have no trailing zeros', function () {
    $this->get('/clients/C1')
        ->assertOk()
        ->assertSee('$100,000 homestead')
        ->assertSee('20% homestead')
        ->assertSee('none')
        ->assertSee('0.95')
        ->assertDontSee('0.9500')
        ->assertDontSee('100000');
});

test('the client list is paginated', function () {
    Client::factory()->count(30)->create();

    $this->get(route('clients.index'))->assertOk()->assertSee('Next');
});

test('a client page shows the invoice header, the line row, staff details and the tax breakdown', function () {
    $this->get('/clients/C1')
        ->assertOk()
        ->assertSeeInOrder(['Invoice', 'Number', 'As of', '2026-07-01', 'Client', 'C1'])
        ->assertSeeInOrder(['<div class="amount-due-label">Amount due</div>', '<div class="amount-due">$212.88</div>'], false)
        ->assertSeeInOrder(['Total', '$212.88'])
        ->assertSee('$212.88')
        ->assertSee('E1, recorded 2026-06-01')
        ->assertSee('Tax year 2026')
        ->assertSee('County value')
        ->assertSee('$360,000')
        ->assertSee('$400,000', false)
        ->assertSee('Tax savings')
        ->assertSee('$709.60')
        ->assertSee('Our fee')
        ->assertSee('30%')
        ->assertSeeInOrder(['ISD', 'CNTY', 'CITY'])
        ->assertSee('$2,850.00')
        ->assertDontSee('2850.00')
        ->assertDontSee('Season')
        ->assertDontSee('<details', false);
});

test('large property values drop the cents but fees and taxes keep them', function () {
    $this->get('/clients/C2')
        ->assertOk()
        ->assertSee('$4,000,000<', false)
        ->assertSee('$3,200,000<', false)
        ->assertDontSee('$4,000,000.00')
        ->assertSee('$38,000.00')
        ->assertSee('$2,500.00');
});

test('a waived fee shows the struck-through calculated fee and a plain note', function () {
    $this->get('/clients/C3')
        ->assertOk()
        ->assertSee('<div class="fee-calculated">$7.52</div>', false)
        ->assertSee('Waived, under $10', false);
});

test('a capped fee shows the struck-through calculated fee and a plain note', function () {
    $this->get('/clients/C2')
        ->assertOk()
        ->assertSee('<div class="fee-calculated">$2,660.00</div>', false)
        ->assertSee('Capped at $2,500', false)
        ->assertSee('$2,569.38');
});

test('a property that has not been billed is listed with its status', function () {
    $this->get('/clients/C3')
        ->assertOk()
        ->assertSee('Not yet billed')
        ->assertSee('P6')
        ->assertSee('Pending, recorded 2026-06-12');
});

test('an adjustment shows previous and new fee, the change, and links to the original invoice', function () {
    Artisan::call('billing:run', ['as_of' => '2026-09-01']);

    $original = Invoice::whereRelation('client', 'external_id', 'C1')->where('type', 'invoice')->sole();

    $this->get('/clients/C1')
        ->assertOk()
        ->assertSeeInOrder(['Adjustment', 'Number', 'As of', '2026-09-01'])
        ->assertSeeInOrder(['<div class="amount-due-label">Additional amount due</div>', '<div class="amount-due">$53.22</div>'], false)
        ->assertSee('Final value revised from $360,000 to $350,000, so tax savings rose from $709.60 to $887.00 and the fee from $212.88 to $266.10.')
        ->assertSee('Amount due')
        ->assertSee('$212.88 &rarr; $266.10', false)
        ->assertDontSee('+$53.22', false)
        ->assertSee('href="#invoice-'.$original->id.'"', false);
});

test('a negative adjustment total is shown as a credit', function () {
    $client = Client::where('external_id', 'C1')->sole();
    Invoice::factory()->adjustment()->for($client)->create(['total' => '-26.10']);

    $this->get('/clients/C1')->assertOk()
        ->assertSeeInOrder(['<div class="amount-due-label">Credit</div>', '<div class="amount-due">$26.10</div>'], false)
        ->assertSee('Credit $26.10')
        ->assertDontSee('-$26.10');
});

test('an unknown client is a 404', function () {
    $this->get('/clients/NOPE')->assertNotFound();
});
