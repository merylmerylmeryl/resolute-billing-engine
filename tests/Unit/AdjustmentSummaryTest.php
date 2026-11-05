<?php

use App\Billing\AdjustmentSummary;
use App\Models\InvoiceLine;

/**
 * @param  array<string, string>  $overrides
 */
function summaryLine(array $overrides = []): InvoiceLine
{
    return new InvoiceLine($overrides + [
        'noticed_value' => '400000.00',
        'final_value' => '360000.00',
        'savings' => '709.60',
        'fee' => '212.88',
    ]);
}

test('it explains a revised final value from the two lines', function () {
    $new = summaryLine(['final_value' => '350000.00', 'savings' => '887.00', 'fee' => '266.10']);

    expect(AdjustmentSummary::describe(summaryLine(), $new))
        ->toBe('Final value revised from $360,000 to $350,000, so tax savings rose from $709.60 to $887.00 and the fee from $212.88 to $266.10.');
});

test('it says fell and names the fee movement when the direction differs from the savings', function () {
    $previous = summaryLine(['savings' => '10000.00', 'fee' => '2500.00']);
    $new = summaryLine(['final_value' => '300000.00', 'savings' => '12000.00', 'fee' => '2400.00']);

    expect(AdjustmentSummary::describe($previous, $new))
        ->toContain('tax savings rose from $10,000.00 to $12,000.00 and the fee fell from $2,500.00 to $2,400.00.');
});

test('it explains a county value change and a savings drop', function () {
    $new = summaryLine(['noticed_value' => '380000.00', 'savings' => '500.00', 'fee' => '150.00']);

    expect(AdjustmentSummary::describe(summaryLine(), $new))
        ->toBe('County value revised from $400,000 to $380,000, so tax savings fell from $709.60 to $500.00 and the fee from $212.88 to $150.00.');
});

test('it says tax rates or exemptions changed when no value did', function () {
    $new = summaryLine(['savings' => '800.00', 'fee' => '240.00']);

    expect(AdjustmentSummary::describe(summaryLine(), $new))->toStartWith('Tax rates or exemptions changed, so ');
});
