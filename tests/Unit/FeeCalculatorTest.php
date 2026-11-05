<?php

use App\Billing\FeeCalculator;
use App\Enums\ExemptionType;
use App\Enums\FeeNote;

/**
 * The spec's jurisdiction table, passed in as data the way billing will pass it.
 *
 * @return list<array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}>
 */
function specJurisdictions(string ...$codes): array
{
    $table = [
        'ISD' => ['code' => 'ISD', 'rate' => '0.9500', 'exemption_type' => ExemptionType::Flat, 'exemption_amount' => '100000.0000'],
        'CNTY' => ['code' => 'CNTY', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '0.2000'],
        'CITY' => ['code' => 'CITY', 'rate' => '0.5200', 'exemption_type' => ExemptionType::None, 'exemption_amount' => null],
    ];

    return array_map(fn (string $code) => $table[$code], $codes);
}

/**
 * A single jurisdiction with no exemption. At a rate of 1.0000, tax is simply value / 100.
 *
 * @return list<array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}>
 */
function plainJurisdiction(string $rate = '1.0000'): array
{
    return [['code' => 'TEST', 'rate' => $rate, 'exemption_type' => ExemptionType::None, 'exemption_amount' => null]];
}

/**
 * Pads a known value to the calculator's scale of 20, so exact strings can be compared.
 */
function atFullScale(string $value): string
{
    return bcadd($value, '0', 20);
}

describe('spec results', function () {
    test('reproduces every number in the spec', function (
        string $noticed,
        string $final,
        bool $homestead,
        array $codes,
        string $rate,
        array $savingsByJurisdiction,
        string $savings,
        string $calculatedFee,
        string $fee,
        ?FeeNote $note,
    ) {
        $result = (new FeeCalculator)->calculate($noticed, $final, $homestead, specJurisdictions(...$codes), $rate);

        expect(array_column($result->jurisdictions, 'code'))->toBe($codes)
            ->and(array_column($result->jurisdictions, 'savings'))->toBe(array_map(atFullScale(...), $savingsByJurisdiction))
            ->and($result->exactSavings)->toBe(atFullScale($savings))
            ->and($result->savings)->toBe($savings)
            ->and($result->feeRate)->toBe($rate)
            ->and($result->calculatedFee)->toBe($calculatedFee)
            ->and($result->fee)->toBe($fee)
            ->and($result->note)->toBe($note);
    })->with([
        'P1, Run 1' => ['400000.00', '360000.00', true, ['ISD', 'CNTY', 'CITY'], '0.30', ['380.00', '121.60', '208.00'], '709.60', '212.88', '212.88', null],
        'P2, half cent rounds up from 69.375' => ['300000.00', '285000.00', false, ['ISD', 'CNTY', 'CITY'], '0.25', ['142.50', '57.00', '78.00'], '277.50', '69.38', '69.38', null],
        'P3, capped' => ['4000000.00', '3200000.00', false, ['ISD', 'CNTY'], '0.25', ['7600.00', '3040.00'], '10640.00', '2660.00', '2500.00', FeeNote::Capped],
        'P4, no change in value' => ['250000.00', '250000.00', false, ['ISD', 'CNTY', 'CITY'], '0.25', ['0.00', '0.00', '0.00'], '0.00', '0.00', '0.00', FeeNote::NoSavings],
        'P5, waived' => ['120000.00', '118000.00', true, ['ISD', 'CNTY'], '0.30', ['19.00', '6.08'], '25.08', '7.52', '0.00', FeeNote::Waived],
        'P1, Run 2' => ['400000.00', '350000.00', true, ['ISD', 'CNTY', 'CITY'], '0.30', ['475.00', '152.00', '260.00'], '887.00', '266.10', '266.10', null],
    ]);

    test('shows the taxable value and tax for each jurisdiction at both values', function () {
        $result = (new FeeCalculator)->calculate('400000.00', '360000.00', true, specJurisdictions('ISD', 'CNTY', 'CITY'), '0.30');

        [$isd, $county, $city] = $result->jurisdictions;

        expect($isd['taxable_at_noticed'])->toBe(atFullScale('300000'))
            ->and($isd['tax_at_noticed'])->toBe(atFullScale('2850'))
            ->and($isd['taxable_at_final'])->toBe(atFullScale('260000'))
            ->and($isd['tax_at_final'])->toBe(atFullScale('2470'))
            ->and($county['taxable_at_noticed'])->toBe(atFullScale('320000'))
            ->and($county['tax_at_noticed'])->toBe(atFullScale('1216'))
            ->and($county['taxable_at_final'])->toBe(atFullScale('288000'))
            ->and($county['tax_at_final'])->toBe(atFullScale('1094.40'))
            ->and($city['taxable_at_noticed'])->toBe(atFullScale('400000'))
            ->and($city['tax_at_noticed'])->toBe(atFullScale('2080'))
            ->and($city['taxable_at_final'])->toBe(atFullScale('360000'))
            ->and($city['tax_at_final'])->toBe(atFullScale('1872'));
    });
});

describe('homestead exemptions', function () {
    test('floors the taxable value at zero when both values are under the flat exemption', function () {
        $result = (new FeeCalculator)->calculate('90000.00', '80000.00', true, specJurisdictions('ISD'), '0.30');

        expect($result->jurisdictions[0]['taxable_at_noticed'])->toBe(atFullScale('0'))
            ->and($result->jurisdictions[0]['taxable_at_final'])->toBe(atFullScale('0'))
            ->and($result->exactSavings)->toBe(atFullScale('0'))
            ->and($result->fee)->toBe('0.00')
            ->and($result->note)->toBe(FeeNote::NoSavings);
    });

    test('floors only the side under the exemption when the values straddle it', function () {
        $result = (new FeeCalculator)->calculate('120000.00', '80000.00', true, specJurisdictions('ISD'), '0.30');

        expect($result->jurisdictions[0]['taxable_at_noticed'])->toBe(atFullScale('20000'))
            ->and($result->jurisdictions[0]['taxable_at_final'])->toBe(atFullScale('0'))
            ->and($result->savings)->toBe('190.00');
    });

    test('keeps every decimal of a percent exemption', function () {
        $jurisdictions = [['code' => 'TEST', 'rate' => '1.0000', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '0.1250']];

        $result = (new FeeCalculator)->calculate('1000.01', '800.00', true, $jurisdictions, '0.30');

        expect($result->jurisdictions[0]['taxable_at_noticed'])->toBe(atFullScale('875.00875'))
            ->and($result->jurisdictions[0]['tax_at_noticed'])->toBe(atFullScale('8.7500875'))
            ->and($result->jurisdictions[0]['taxable_at_final'])->toBe(atFullScale('700'))
            ->and($result->exactSavings)->toBe(atFullScale('1.7500875'));
    });

    test('ignores exemptions on a non-homestead property and records none as applied', function () {
        $result = (new FeeCalculator)->calculate('120000.00', '118000.00', false, specJurisdictions('ISD', 'CNTY'), '0.30');

        [$isd, $county] = $result->jurisdictions;

        expect($isd['taxable_at_noticed'])->toBe(atFullScale('120000'))
            ->and($isd['exemption_type'])->toBe('none')
            ->and($isd['exemption_amount'])->toBeNull()
            ->and($county['taxable_at_noticed'])->toBe(atFullScale('120000'))
            ->and($county['savings'])->toBe(atFullScale('7.60'))
            ->and($result->savings)->toBe('26.60');
    });

    test('records the exemption applied on a homestead property', function () {
        $result = (new FeeCalculator)->calculate('120000.00', '118000.00', true, specJurisdictions('ISD', 'CNTY', 'CITY'), '0.30');

        [$isd, $county, $city] = $result->jurisdictions;

        expect($isd['rate'])->toBe('0.9500')
            ->and($isd['exemption_type'])->toBe('flat')
            ->and($isd['exemption_amount'])->toBe('100000.0000')
            ->and($county['exemption_type'])->toBe('percent')
            ->and($county['exemption_amount'])->toBe('0.2000')
            ->and($city['exemption_type'])->toBe('none')
            ->and($city['exemption_amount'])->toBeNull();
    });
});

describe('fee rules', function () {
    test('charges nothing and keeps the negative savings when the final value is higher', function () {
        $result = (new FeeCalculator)->calculate('300000.00', '310000.00', false, specJurisdictions('ISD'), '0.30');

        expect($result->exactSavings)->toBe(atFullScale('-95'))
            ->and($result->savings)->toBe('-95.00')
            ->and($result->calculatedFee)->toBe('0.00')
            ->and($result->fee)->toBe('0.00')
            ->and($result->note)->toBe(FeeNote::NoSavings);
    });

    test('applies the waiver and cap after rounding', function (string $noticed, string $jurisdictionRate, string $calculatedFee, string $fee, ?FeeNote $note) {
        $result = (new FeeCalculator)->calculate($noticed, '0.00', false, plainJurisdiction($jurisdictionRate), '0.25');

        expect($result->calculatedFee)->toBe($calculatedFee)
            ->and($result->fee)->toBe($fee)
            ->and($result->note)->toBe($note);
    })->with([
        'exactly $10.00 is kept' => ['4000.00', '1.0000', '10.00', '10.00', null],
        '$9.99 is waived' => ['3996.00', '1.0000', '9.99', '0.00', FeeNote::Waived],
        '$9.995 rounds up to $10.00 and is kept' => ['3998.00', '1.0000', '10.00', '10.00', null],
        '$9.994999 rounds down to $9.99 and is waived' => ['99949.99', '0.0400', '9.99', '0.00', FeeNote::Waived],
        'exactly $2,500.00 is not marked as capped' => ['1000000.00', '1.0000', '2500.00', '2500.00', null],
        '$2,500.01 is capped' => ['1000004.00', '1.0000', '2500.01', '2500.00', FeeNote::Capped],
    ]);

    test('takes the percentage of the exact savings, not the rounded savings', function () {
        $result = (new FeeCalculator)->calculate('10001.60', '0.00', false, plainJurisdiction(), '0.25');

        expect($result->exactSavings)->toBe(atFullScale('100.016'))
            ->and($result->savings)->toBe('100.02')
            ->and($result->calculatedFee)->toBe('25.00')
            ->and($result->fee)->toBe('25.00');
    });

    test('rounds the savings snapshot half away from zero in both directions', function (string $noticed, string $final, string $savings) {
        $result = (new FeeCalculator)->calculate($noticed, $final, false, plainJurisdiction(), '0.30');

        expect($result->savings)->toBe($savings);
    })->with([
        'positive, half rounds up' => ['184.50', '0.00', '1.85'],
        'positive, below half rounds down' => ['184.32', '0.00', '1.84'],
        'negative, half rounds away from zero' => ['0.00', '184.50', '-1.85'],
        'negative, below half rounds toward zero' => ['0.00', '184.32', '-1.84'],
        'tiny negative never shows as -0.00' => ['100.00', '100.01', '0.00'],
    ]);

    test('chooses the fee rate from the enrolled property count', function (int $enrolledCount, string $rate) {
        expect((new FeeCalculator)->rateFor($enrolledCount))->toBe($rate);
    })->with([
        '1 property' => [1, '0.30'],
        '2 properties' => [2, '0.30'],
        '3 properties' => [3, '0.25'],
        '4 properties' => [4, '0.25'],
    ]);
});

describe('invalid data', function () {
    test('rejects jurisdiction data that cannot give a correct fee', function (array $jurisdictions) {
        expect(fn () => (new FeeCalculator)->calculate('400000.00', '360000.00', true, $jurisdictions, '0.30'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'no jurisdictions' => [[]],
        'flat exemption with no amount' => [[['code' => 'TEST', 'rate' => '0.9500', 'exemption_type' => ExemptionType::Flat, 'exemption_amount' => null]]],
        'percent exemption with no amount' => [[['code' => 'TEST', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => null]]],
        'percent exemption with an empty amount' => [[['code' => 'TEST', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '']]],
        'percent exemption over 100%' => [[['code' => 'TEST', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '1.5000']]],
        'negative percent exemption' => [[['code' => 'TEST', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '-0.5000']]],
        'negative flat exemption' => [[['code' => 'TEST', 'rate' => '0.9500', 'exemption_type' => ExemptionType::Flat, 'exemption_amount' => '-1000.0000']]],
        'empty rate' => [[['code' => 'TEST', 'rate' => '', 'exemption_type' => ExemptionType::None, 'exemption_amount' => null]]],
        'rate as a float' => [[['code' => 'TEST', 'rate' => 0.95, 'exemption_type' => ExemptionType::None, 'exemption_amount' => null]]],
        'exemption amount as a float' => [[['code' => 'TEST', 'rate' => '0.3800', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => 0.20]]],
    ]);

    test('rejects an invalid exemption on a non-homestead property too', function () {
        $jurisdictions = [['code' => 'TEST', 'rate' => '0.9500', 'exemption_type' => ExemptionType::Flat, 'exemption_amount' => null]];

        expect(fn () => (new FeeCalculator)->calculate('400000.00', '360000.00', false, $jurisdictions, '0.30'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('rejects a value or fee rate that is not a plain decimal', function (string $noticed, string $final, string $feeRate) {
        expect(fn () => (new FeeCalculator)->calculate($noticed, $final, false, plainJurisdiction(), $feeRate))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'empty noticed value' => ['', '360000.00', '0.30'],
        'empty final value' => ['400000.00', '', '0.30'],
        'empty fee rate' => ['400000.00', '360000.00', ''],
        'scientific notation' => ['1e5', '360000.00', '0.30'],
        'leading space' => [' 400000.00', '360000.00', '0.30'],
        'trailing newline' => ["400000.00\n", '360000.00', '0.30'],
    ]);

    test('accepts an exemption of exactly zero', function (ExemptionType $type) {
        $jurisdictions = [['code' => 'TEST', 'rate' => '0.9500', 'exemption_type' => $type, 'exemption_amount' => '0.0000']];

        $result = (new FeeCalculator)->calculate('400000.00', '360000.00', true, $jurisdictions, '0.30');

        expect($result->jurisdictions[0]['taxable_at_noticed'])->toBe(atFullScale('400000'))
            ->and($result->savings)->toBe('380.00');
    })->with([
        'flat' => [ExemptionType::Flat],
        'percent' => [ExemptionType::Percent],
    ]);

    test('accepts a percent exemption of exactly 100%', function () {
        $jurisdictions = [['code' => 'TEST', 'rate' => '0.9500', 'exemption_type' => ExemptionType::Percent, 'exemption_amount' => '1.0000']];

        $result = (new FeeCalculator)->calculate('400000.00', '360000.00', true, $jurisdictions, '0.30');

        expect($result->jurisdictions[0]['taxable_at_noticed'])->toBe(atFullScale('0'))
            ->and($result->fee)->toBe('0.00')
            ->and($result->note)->toBe(FeeNote::NoSavings);
    });
});

describe('breakdown', function () {
    test('gives the snapshot with the exact values as strings', function () {
        $result = (new FeeCalculator)->calculate('120000.00', '118000.00', true, specJurisdictions('ISD'), '0.30');

        expect($result->toBreakdown())->toBe([
            'exact_savings' => atFullScale('19'),
            'calculated_fee' => '5.70',
            'note' => 'waived',
            'jurisdictions' => [
                [
                    'code' => 'ISD',
                    'rate' => '0.9500',
                    'exemption_type' => 'flat',
                    'exemption_amount' => '100000.0000',
                    'taxable_at_noticed' => atFullScale('20000'),
                    'tax_at_noticed' => atFullScale('190'),
                    'taxable_at_final' => atFullScale('18000'),
                    'tax_at_final' => atFullScale('171'),
                    'savings' => atFullScale('19'),
                ],
            ],
        ]);
    });

    test('has a null note when the fee is charged in full', function () {
        $result = (new FeeCalculator)->calculate('400000.00', '360000.00', true, specJurisdictions('ISD'), '0.30');

        expect($result->toBreakdown()['note'])->toBeNull();
    });
});
