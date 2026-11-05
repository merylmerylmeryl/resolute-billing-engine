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

test('the client list is paginated', function () {
    Client::factory()->count(30)->create();

    $this->get(route('clients.index'))->assertOk()->assertSee('Next');
});

test('a client page shows the invoice total and the line details', function () {
    $this->get('/clients/C1')
        ->assertOk()
        ->assertSee('212.88')
        ->assertSee('2026-07-01')
        ->assertSee('P1')
        ->assertSee('E1')
        ->assertSee('400,000.00')
        ->assertSee('360,000.00')
        ->assertSee('709.60')
        ->assertSee('30%')
        ->assertSeeInOrder(['ISD', 'CNTY', 'CITY']);
});

test('the tax breakdown shows money rounded with commas, not the raw calculator string', function () {
    $this->get('/clients/C1')
        ->assertOk()
        ->assertSee('2,850.00')
        ->assertDontSee('2850.00000000000000000000')
        ->assertDontSee('2850.00');
});

test('a waived fee is labelled with its calculated fee', function () {
    $this->get('/clients/C3')
        ->assertOk()
        ->assertSee('waived')
        ->assertSee('7.52')
        ->assertSee('0.00');
});

test('a capped fee is labelled with its calculated fee', function () {
    $this->get('/clients/C2')
        ->assertOk()
        ->assertSee('capped')
        ->assertSee('2,660.00')
        ->assertSee('2,500.00')
        ->assertSee('2,569.38');
});

test('a property that has not been billed is listed with its status', function () {
    $this->get('/clients/C3')
        ->assertOk()
        ->assertSee('Not yet billed')
        ->assertSee('P6')
        ->assertSee('pending');
});

test('an adjustment shows the change, links to the original invoice and shows the fee before and after', function () {
    Artisan::call('billing:run', ['as_of' => '2026-09-01']);

    $original = Invoice::whereRelation('client', 'external_id', 'C1')->where('type', 'invoice')->sole();

    $this->get('/clients/C1')
        ->assertOk()
        ->assertSee('Adjustment')
        ->assertSee('+53.22', false)
        ->assertSee('212.88')
        ->assertSee('266.10')
        ->assertSee('2026-09-01')
        ->assertSee('href="#invoice-'.$original->id.'"', false);
});

test('a negative adjustment is shown with a minus sign', function () {
    $client = Client::where('external_id', 'C1')->sole();
    Invoice::factory()->adjustment()->for($client)->create(['total' => '-26.10']);

    $this->get('/clients/C1')->assertOk()->assertSee('-26.10');
});

test('an unknown client is a 404', function () {
    $this->get('/clients/NOPE')->assertNotFound();
});
