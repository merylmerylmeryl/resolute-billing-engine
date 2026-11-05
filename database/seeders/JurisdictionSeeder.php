<?php

namespace Database\Seeders;

use App\Enums\ExemptionType;
use App\Models\Jurisdiction;
use Illuminate\Database\Seeder;

class JurisdictionSeeder extends Seeder
{
    /**
     * Seed the spec's jurisdictions. Safe to run repeatedly.
     */
    public function run(): void
    {
        $jurisdictions = [
            ['code' => 'ISD', 'rate' => '0.95', 'exemption_type' => ExemptionType::Flat, 'exemption_amount' => '100000'],
            ['code' => 'CNTY', 'rate' => '0.38', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '0.20'],
            ['code' => 'CITY', 'rate' => '0.52', 'exemption_type' => ExemptionType::None, 'exemption_amount' => null],
        ];

        foreach ($jurisdictions as $jurisdiction) {
            Jurisdiction::updateOrCreate(['code' => $jurisdiction['code']], $jurisdiction);
        }
    }
}
