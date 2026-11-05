<?php

use App\Billing\Money;

test('money is rounded half up to 2 decimals with thousands commas', function (string $value, string $expected) {
    expect(Money::format($value))->toBe($expected);
})->with([
    'raw calculator string' => ['2850.00000000000000000000', '2,850.00'],
    'half rounds up' => ['69.375', '69.38'],
    'just under half' => ['9.994999', '9.99'],
    'millions' => ['1234567.891', '1,234,567.89'],
    'under a thousand' => ['999.99', '999.99'],
    'zero' => ['0', '0.00'],
    'negative' => ['-26.10', '-26.10'],
    'negative half rounds away from zero' => ['-1.845', '-1.85'],
    'tiny negative is not shown as -0.00' => ['-0.001', '0.00'],
]);

test('a plus sign is only added to positive values when asked', function () {
    expect(Money::format('53.22', true))->toBe('+53.22')
        ->and(Money::format('-26.10', true))->toBe('-26.10')
        ->and(Money::format('0.00', true))->toBe('0.00')
        ->and(Money::format('53.22'))->toBe('53.22');
});

test('a rate is shown as a percent', function (string $rate, string $expected) {
    expect(Money::percent($rate))->toBe($expected);
})->with([
    ['0.3000', '30%'],
    ['0.2500', '25%'],
    ['0.2750', '27.5%'],
    ['0.2000', '20%'],
]);
