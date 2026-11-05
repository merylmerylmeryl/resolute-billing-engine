<?php

use App\Enums\ExemptionType;
use App\Models\Jurisdiction;
use App\Models\User;
use Database\Seeders\JurisdictionSeeder;

test('seeds the rate and homestead exemption of each spec jurisdiction', function (string $code, string $rate, ExemptionType $type, ?string $amount) {
    $this->seed(JurisdictionSeeder::class);

    $jurisdiction = Jurisdiction::where('code', $code)->sole();

    expect($jurisdiction->rate)->toBe($rate)
        ->and($jurisdiction->exemption_type)->toBe($type)
        ->and($jurisdiction->exemption_amount)->toBe($amount);
})->with([
    'ISD: first $100,000' => ['ISD', '0.9500', ExemptionType::Flat, '100000.0000'],
    'CNTY: 20% of value' => ['CNTY', '0.3800', ExemptionType::Percent, '0.2000'],
    'CITY: no exemption' => ['CITY', '0.5200', ExemptionType::None, null],
]);

test('seeding twice creates no duplicates and restores changed values', function () {
    $this->seed(JurisdictionSeeder::class);
    Jurisdiction::where('code', 'ISD')->sole()->update(['rate' => '1.00']);

    $this->seed(JurisdictionSeeder::class);

    expect(Jurisdiction::count())->toBe(3)
        ->and(Jurisdiction::where('code', 'ISD')->sole()->rate)->toBe('0.9500');
});

test('the database seeder loads jurisdictions and no default user', function () {
    $this->seed();

    expect(Jurisdiction::count())->toBe(3)
        ->and(User::count())->toBe(0);
});
