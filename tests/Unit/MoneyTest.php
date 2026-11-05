<?php

use App\Billing\Money;

test('money is rounded half up to 2 decimals with thousands commas', function (string $value, string $expected) {
    expect(Money::format($value))->toBe($expected);
})->with([
    'raw calculator string' => ['2850.00000000000000000000', '$2,850.00'],
    'half rounds up' => ['69.375', '$69.38'],
    'just under half' => ['9.994999', '$9.99'],
    'millions' => ['1234567.891', '$1,234,567.89'],
    'under a thousand' => ['999.99', '$999.99'],
    'zero' => ['0', '$0.00'],
    'negative' => ['-26.10', '-$26.10'],
    'negative half rounds away from zero' => ['-1.845', '-$1.85'],
    'tiny negative is not shown as -$0.00' => ['-0.001', '$0.00'],
]);

test('an adjustment amount is a credit when negative, never shown with a plus or minus sign', function () {
    expect(Money::amountDue('53.22'))->toBe('$53.22')
        ->and(Money::amountDue('-26.10'))->toBe('Credit $26.10')
        ->and(Money::amountDue('0.00'))->toBe('$0.00')
        ->and(Money::amountDue('-0.001'))->toBe('$0.00');
});

test('a total has a small label and an unsigned figure', function (string $total, bool $isAdjustment, string $label, string $figure) {
    expect(Money::totalLabel($total, $isAdjustment))->toBe($label)
        ->and(Money::absolute($total))->toBe($figure);
})->with([
    'invoice' => ['212.88', false, 'Amount due', '$212.88'],
    'zero invoice' => ['0.00', false, 'Amount due', '$0.00'],
    'positive adjustment' => ['53.22', true, 'Additional amount due', '$53.22'],
    'negative adjustment' => ['-26.10', true, 'Credit', '$26.10'],
    'zero adjustment' => ['0.00', true, 'Amount due', '$0.00'],
]);

test('a rate is shown as a percent', function (string $rate, string $expected) {
    expect(Money::percent($rate))->toBe($expected);
})->with([
    ['0.3000', '30%'],
    ['0.2500', '25%'],
    ['0.2750', '27.5%'],
    ['0.2000', '20%'],
]);

test('property and taxable values drop the cents only when they are .00', function (string $value, string $expected) {
    expect(Money::value($value))->toBe($expected);
})->with([
    ['4000000.00', '$4,000,000'],
    ['10000000.00000000000000000000', '$10,000,000'],
    ['4000000.50', '$4,000,000.50'],
    ['0', '$0'],
]);

test('a jurisdiction rate drops trailing zeros', function (string $rate, string $expected) {
    expect(Money::rate($rate))->toBe($expected);
})->with([
    ['0.9500', '0.95'],
    ['1.0000', '1'],
    ['0.3800', '0.38'],
    ['0.1250', '0.125'],
]);
