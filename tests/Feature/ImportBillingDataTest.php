<?php

use App\Enums\EventStatus;
use App\Models\Client;
use App\Models\Event;
use App\Models\Property;
use Database\Seeders\JurisdictionSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(JurisdictionSeeder::class);
});

/**
 * Writes a data file for one test and returns its path. It is removed when the run ends.
 *
 * @param  array<string, mixed>|string  $contents
 */
function writeImportFile(array|string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'billing-import-');
    file_put_contents($path, is_string($contents) ? $contents : json_encode($contents));
    register_shutdown_function(fn () => unlink($path));

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function propertyRow(array $overrides = []): array
{
    return [...['id' => 'P1', 'client_id' => 'C1', 'homestead' => true, 'jurisdictions' => ['ISD', 'CNTY', 'CITY']], ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventRow(array $overrides = []): array
{
    return [...['source_id' => 'E1', 'property_id' => 'P1', 'recorded_at' => '2026-06-01', 'status' => 'final', 'noticed_value' => 400000, 'final_value' => 360000], ...$overrides];
}

describe('loading a file', function () {
    test('loads the sample file', function () {
        $this->artisan('billing:import', ['path' => 'database/data/sample.json'])
            ->expectsOutputToContain('Clients created: 3')
            ->expectsOutputToContain('Properties created: 6')
            ->expectsOutputToContain('Properties updated: 0')
            ->expectsOutputToContain('Events created: 7')
            ->expectsOutputToContain('Duplicates skipped: 0')
            ->expectsOutputToContain('Rows rejected: 0')
            ->assertSuccessful();

        $p1 = Property::where('external_id', 'P1')->sole();
        $p3 = Property::where('external_id', 'P3')->sole();
        $pendingEvent = Event::where('source_id', 'E6')->sole();
        $finalEvent = Event::where('source_id', 'E7')->sole();

        expect(Client::count())->toBe(3)
            ->and(Property::count())->toBe(6)
            ->and(Event::count())->toBe(7)
            ->and($p1->client->external_id)->toBe('C1')
            ->and($p1->homestead)->toBeTrue()
            ->and($p1->jurisdictions->pluck('code')->sort()->values()->all())->toBe(['CITY', 'CNTY', 'ISD'])
            ->and($p3->homestead)->toBeFalse()
            ->and($p3->jurisdictions->pluck('code')->sort()->values()->all())->toBe(['CNTY', 'ISD'])
            ->and($pendingEvent->status)->toBe(EventStatus::Pending)
            ->and($pendingEvent->final_value)->toBeNull()
            ->and($finalEvent->property->external_id)->toBe('P1')
            ->and($finalEvent->recorded_at->toDateString())->toBe('2026-08-15')
            ->and($finalEvent->noticed_value)->toBe('400000.00')
            ->and($finalEvent->final_value)->toBe('350000.00');
    });

    test('loads a file from any path', function () {
        $path = writeImportFile(['properties' => [propertyRow(['id' => 'P99', 'client_id' => 'C99'])], 'events' => [eventRow(['source_id' => 'E99', 'property_id' => 'P99'])]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        expect(Property::where('external_id', 'P99')->exists())->toBeTrue()
            ->and(Event::where('source_id', 'E99')->exists())->toBeTrue();
    });

    test('accepts integer ids by converting them to strings', function () {
        $path = writeImportFile([
            'properties' => [propertyRow(['id' => 101, 'client_id' => 7])],
            'events' => [eventRow(['source_id' => 5001, 'property_id' => 101])],
        ]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        $event = Event::sole();

        expect($event->source_id)->toBe('5001')
            ->and($event->property->external_id)->toBe('101')
            ->and($event->property->client->external_id)->toBe('7');
    });

    test('treats an integer id and the same id as a string as one record', function () {
        $integers = writeImportFile([
            'properties' => [propertyRow(['id' => 101, 'client_id' => 7])],
            'events' => [eventRow(['source_id' => 5001, 'property_id' => 101])],
        ]);
        $strings = writeImportFile([
            'properties' => [propertyRow(['id' => '101', 'client_id' => '7'])],
            'events' => [eventRow(['source_id' => '5001', 'property_id' => '101'])],
        ]);

        $this->artisan('billing:import', ['path' => $integers])->assertSuccessful();

        $this->artisan('billing:import', ['path' => $strings])
            ->expectsOutputToContain('Clients created: 0')
            ->expectsOutputToContain('Properties created: 0')
            ->expectsOutputToContain('Events created: 0')
            ->expectsOutputToContain('Duplicates skipped: 1')
            ->assertSuccessful();
    });

    test('converts JSON numbers to exact money strings', function () {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(['noticed_value' => 123456789012.34, 'final_value' => 400000.1])]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        $event = Event::sole();

        expect($event->noticed_value)->toBe('123456789012.34')
            ->and($event->final_value)->toBe('400000.10');
    });

    test('keeps file order so same-date events are recorded in that order', function () {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [
            eventRow(['source_id' => 'Z', 'recorded_at' => '2026-06-05']),
            eventRow(['source_id' => 'A', 'recorded_at' => '2026-06-05']),
            eventRow(['source_id' => 'M', 'recorded_at' => '2026-06-05']),
        ]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        expect(Event::orderBy('id')->pluck('source_id')->all())->toBe(['Z', 'A', 'M']);
    });
});

describe('importing again', function () {
    test('creates nothing when the same file is imported twice', function () {
        $this->artisan('billing:import', ['path' => 'database/data/sample.json'])->assertSuccessful();

        $this->artisan('billing:import', ['path' => 'database/data/sample.json'])
            ->expectsOutputToContain('Clients created: 0')
            ->expectsOutputToContain('Properties created: 0')
            ->expectsOutputToContain('Properties updated: 0')
            ->expectsOutputToContain('Events created: 0')
            ->expectsOutputToContain('Duplicates skipped: 7')
            ->assertSuccessful();

        expect(Client::count())->toBe(3)
            ->and(Property::count())->toBe(6)
            ->and(Event::count())->toBe(7);
    });

    test('updates changed property data without duplicating the property', function () {
        $this->artisan('billing:import', ['path' => writeImportFile(['properties' => [propertyRow()], 'events' => []])])->assertSuccessful();
        $changed = writeImportFile(['properties' => [propertyRow(['homestead' => false, 'jurisdictions' => ['ISD']])], 'events' => []]);

        $this->artisan('billing:import', ['path' => $changed])
            ->expectsOutputToContain('Properties created: 0')
            ->expectsOutputToContain('Properties updated: 1')
            ->assertSuccessful();

        $property = Property::sole();

        expect($property->homestead)->toBeFalse()
            ->and($property->jurisdictions->pluck('code')->all())->toBe(['ISD']);
    });

    test('counts a jurisdiction change alone as an update', function () {
        $this->artisan('billing:import', ['path' => writeImportFile(['properties' => [propertyRow()], 'events' => []])])->assertSuccessful();
        $changed = writeImportFile(['properties' => [propertyRow(['jurisdictions' => ['ISD', 'CNTY']])], 'events' => []]);

        $this->artisan('billing:import', ['path' => $changed])
            ->expectsOutputToContain('Properties updated: 1')
            ->assertSuccessful();
    });

    test('keeps the first event and warns when a source_id repeats with different values', function (array $repeat) {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(), eventRow($repeat)]]);

        $exitCode = Artisan::call('billing:import', ['path' => $path]);

        $event = Event::sole();

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('events[1] (E1)', 'first one was kept', 'Events created: 1', 'Duplicates skipped: 1', 'Rows rejected: 0')
            ->and($event->final_value)->toBe('360000.00')
            ->and($event->noticed_value)->toBe('400000.00')
            ->and($event->status)->toBe(EventStatus::Final)
            ->and($event->recorded_at->toDateString())->toBe('2026-06-01');
    })->with([
        'different final value' => [['final_value' => 350000]],
        'different noticed value' => [['noticed_value' => 410000]],
        'different date' => [['recorded_at' => '2026-07-01']],
        'different status' => [['status' => 'pending', 'final_value' => null]],
    ]);

    test('does not warn when a source_id repeats with the same values', function () {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(), eventRow()]]);

        $this->artisan('billing:import', ['path' => $path])
            ->doesntExpectOutputToContain('first one was kept')
            ->expectsOutputToContain('Duplicates skipped: 1')
            ->assertSuccessful();

        expect(Event::count())->toBe(1);
    });

    test('rejects a row that moves a property to a different client', function () {
        $this->artisan('billing:import', ['path' => writeImportFile(['properties' => [propertyRow()], 'events' => []])])->assertSuccessful();
        $path = writeImportFile(['properties' => [
            propertyRow(['client_id' => 'C2', 'homestead' => false]),
            propertyRow(['id' => 'P2', 'client_id' => 'C3']),
        ], 'events' => []]);

        $this->artisan('billing:import', ['path' => $path])
            ->expectsOutputToContain('properties[0] (P1)')
            ->expectsOutputToContain('Rows rejected: 1')
            ->assertFailed();

        $property = Property::where('external_id', 'P1')->sole();

        expect($property->client->external_id)->toBe('C1')
            ->and($property->homestead)->toBeTrue()
            ->and(Client::where('external_id', 'C2')->exists())->toBeFalse()
            ->and(Property::where('external_id', 'P2')->exists())->toBeTrue();
    });
});

describe('bad files', function () {
    test('fails cleanly and loads nothing when the file cannot be used', function (Closure $path, string $message) {
        $this->artisan('billing:import', ['path' => $path()])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(Client::count())->toBe(0)
            ->and(Property::count())->toBe(0)
            ->and(Event::count())->toBe(0);
    })->with([
        'missing file' => [fn () => '/no/such/file.json', 'Could not read'],
        'path that looks like console markup' => [fn () => '/no/such/<fg=bogus>.json', 'Could not read [/no/such/<fg=bogus>.json]'],
        'directory' => [fn () => sys_get_temp_dir(), 'Could not read'],
        'malformed JSON' => [fn () => writeImportFile('{"properties": ['), 'not valid JSON'],
        'not a JSON object' => [fn () => writeImportFile('5'), 'properties'],
        'missing properties key' => [fn () => writeImportFile(['events' => [eventRow()]]), 'properties'],
        'missing events key' => [fn () => writeImportFile(['properties' => [propertyRow()]]), 'events'],
        'events is not a list' => [fn () => writeImportFile(['properties' => [propertyRow()], 'events' => 'none']), 'events'],
    ]);
});

describe('bad rows', function () {
    test('skips and reports a bad property row while valid rows still load', function (mixed $badRow, string $reason) {
        $path = writeImportFile(['properties' => [propertyRow(), $badRow], 'events' => [eventRow()]]);

        $exitCode = Artisan::call('billing:import', ['path' => $path]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('properties[1]', $reason, 'Rows rejected: 1')
            ->and(Property::pluck('external_id')->all())->toBe(['P1'])
            ->and(Client::pluck('external_id')->all())->toBe(['C1'])
            ->and(Event::count())->toBe(1);
    })->with([
        'unknown jurisdiction' => [propertyRow(['id' => 'P2', 'client_id' => 'C2', 'jurisdictions' => ['ISD', 'NOPE']]), 'Unknown jurisdiction [NOPE]'],
        'empty jurisdiction list' => [propertyRow(['id' => 'P2', 'client_id' => 'C2', 'jurisdictions' => []]), 'jurisdictions field is required'],
        'jurisdiction is not a code' => [propertyRow(['id' => 'P2', 'client_id' => 'C2', 'jurisdictions' => [5]]), 'must be a string'],
        'homestead is not a boolean' => [propertyRow(['id' => 'P2', 'client_id' => 'C2', 'homestead' => 'yes']), 'homestead field must be true or false'],
        'id as a decimal number' => [propertyRow(['id' => 1.5, 'client_id' => 'C2']), 'id field must be a string'],
        'client_id as a decimal number' => [propertyRow(['id' => 'P2', 'client_id' => 1.5]), 'client id field must be a string'],
        'missing id' => [array_diff_key(propertyRow(['client_id' => 'C2']), ['id' => 0]), 'id field is required'],
        'missing client_id' => [array_diff_key(propertyRow(['id' => 'P2']), ['client_id' => 0]), 'client id field is required'],
        'missing homestead' => [array_diff_key(propertyRow(['id' => 'P2', 'client_id' => 'C2']), ['homestead' => 0]), 'homestead field is required'],
        'missing jurisdictions' => [array_diff_key(propertyRow(['id' => 'P2', 'client_id' => 'C2']), ['jurisdictions' => 0]), 'jurisdictions field is required'],
        'not an object' => ['P2', 'must be an object'],
    ]);

    test('skips and reports a bad event row while valid rows still load', function (mixed $badRow, string $reason) {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(['source_id' => 'GOOD']), $badRow]]);

        $exitCode = Artisan::call('billing:import', ['path' => $path]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('events[1]', $reason, 'Events created: 1', 'Rows rejected: 1')
            ->and(Event::pluck('source_id')->all())->toBe(['GOOD']);
    })->with([
        'unknown property' => [eventRow(['property_id' => 'P9']), 'Unknown property [P9]'],
        'unknown property with an integer id' => [eventRow(['property_id' => 9]), 'Unknown property [9]'],
        'source_id as a decimal number' => [eventRow(['source_id' => 1.5]), 'source id field must be a string'],
        'property_id as a decimal number' => [eventRow(['property_id' => 1.5]), 'property id field must be a string'],
        'final with no value' => [eventRow(['final_value' => null]), 'final value field is required'],
        'final with the value left out' => [array_diff_key(eventRow(), ['final_value' => 0]), 'final value field is required'],
        'pending with a value' => [eventRow(['status' => 'pending']), 'final value field is prohibited'],
        'pending with a value of zero' => [eventRow(['status' => 'pending', 'final_value' => 0]), 'final value field is prohibited'],
        'invalid status' => [eventRow(['status' => 'settled']), 'status is invalid'],
        'status is not a string' => [eventRow(['status' => 5]), 'status is invalid'],
        'invalid date' => [eventRow(['recorded_at' => '2026-02-30']), 'recorded at field must match the format'],
        'date in the wrong format' => [eventRow(['recorded_at' => '06/01/2026']), 'recorded at field must match the format'],
        'non-numeric noticed value' => [eventRow(['noticed_value' => 'abc']), 'noticed value field must be a non-negative decimal'],
        'non-numeric final value' => [eventRow(['final_value' => 'abc']), 'final value field must be a non-negative decimal'],
        'noticed value as a boolean' => [eventRow(['noticed_value' => true]), 'noticed value field must be a non-negative decimal'],
        'scientific notation that billing would crash on' => [eventRow(['noticed_value' => '1e5']), 'noticed value field must be a non-negative decimal'],
        'negative noticed value' => [eventRow(['noticed_value' => -1]), 'noticed value field must be a non-negative decimal'],
        'negative final value' => [eventRow(['final_value' => -0.01]), 'final value field must be a non-negative decimal'],
        'final with a blank value' => [eventRow(['final_value' => '  ']), 'final value field is required'],
        'three decimals as a string' => [eventRow(['noticed_value' => '100.125']), 'noticed value field must be a non-negative decimal'],
        'three decimals as a JSON number' => [eventRow(['final_value' => 100.125]), 'final value field must be a non-negative decimal'],
        'three decimals on the largest whole number' => [eventRow(['noticed_value' => 123456789012.345]), 'noticed value field must be a non-negative decimal'],
        'thirteen whole digits as a string' => [eventRow(['noticed_value' => '1234567890123']), 'noticed value field must be a non-negative decimal'],
        'thirteen whole digits as a JSON number' => [eventRow(['final_value' => 1234567890123.45]), 'final value field must be a non-negative decimal'],
        'four hundred digits' => [eventRow(['noticed_value' => str_repeat('9', 400)]), 'noticed value field must be a non-negative decimal'],
        'missing source_id' => [array_diff_key(eventRow(), ['source_id' => 0]), 'source id field is required'],
        'missing property_id' => [array_diff_key(eventRow(), ['property_id' => 0]), 'property id field is required'],
        'missing recorded_at' => [array_diff_key(eventRow(), ['recorded_at' => 0]), 'recorded at field is required'],
        'missing status' => [array_diff_key(eventRow(), ['status' => 0]), 'status field is required'],
        'missing noticed_value' => [array_diff_key(eventRow(), ['noticed_value' => 0]), 'noticed value field is required'],
        'not an object' => ['E2', 'must be an object'],
    ]);

    test('reports an id that looks like console markup, and finishes the summary', function () {
        $markup = '<fg=bogus>';
        $path = writeImportFile([
            'properties' => [propertyRow()],
            'events' => [
                eventRow(['source_id' => $markup]),
                eventRow(['source_id' => $markup, 'final_value' => 350000]),
                eventRow(['source_id' => 'E2', 'property_id' => $markup]),
            ],
        ]);

        $exitCode = Artisan::call('billing:import', ['path' => $path]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain(
                "events[1] ({$markup}): This source_id repeats",
                "Unknown property [{$markup}]",
                'Duplicates skipped: 1',
                'Rows rejected: 1',
            );
    });

    test('rejects the events of a rejected property as unknown', function () {
        $path = writeImportFile(['properties' => [propertyRow(['jurisdictions' => []])], 'events' => [eventRow()]]);

        $this->artisan('billing:import', ['path' => $path])
            ->expectsOutputToContain('Unknown property [P1]')
            ->expectsOutputToContain('Rows rejected: 2')
            ->assertFailed();

        expect(Event::count())->toBe(0);
    });

    test('treats a blank final value on a pending row as no value, and can import it again', function (string $blank) {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(['status' => 'pending', 'final_value' => $blank])]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();
        $this->artisan('billing:import', ['path' => $path])
            ->doesntExpectOutputToContain('first one was kept')
            ->expectsOutputToContain('Duplicates skipped: 1')
            ->assertSuccessful();

        expect(Event::sole()->final_value)->toBeNull();
    })->with([
        'empty' => [''],
        'spaces' => ['   '],
    ]);

    test('accepts the largest value the column can hold', function () {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(['noticed_value' => '999999999999.99'])]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        expect(Event::sole()->noticed_value)->toBe('999999999999.99');
    });

    test('accepts a final value of zero and numbers written as strings', function () {
        $path = writeImportFile(['properties' => [propertyRow()], 'events' => [eventRow(['noticed_value' => '400000.50', 'final_value' => 0])]]);

        $this->artisan('billing:import', ['path' => $path])->assertSuccessful();

        $event = Event::sole();

        expect($event->noticed_value)->toBe('400000.50')
            ->and($event->final_value)->toBe('0.00');
    });
});
