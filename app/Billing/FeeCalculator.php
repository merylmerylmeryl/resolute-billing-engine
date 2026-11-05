<?php

namespace App\Billing;

use App\Enums\ExemptionType;
use App\Enums\FeeNote;
use InvalidArgumentException;

/**
 * Calculates a property's tax savings and fee from the values it is given.
 *
 * It runs no queries and uses no floats: exemption, then tax per jurisdiction, then
 * the sum, savings and fee percentage, all unrounded. Only then does it round to the
 * cent and apply the $10 waiver and the $2,500 cap.
 */
class FeeCalculator
{
    /**
     * Enough decimals that nothing is ever truncated: values have 2, rates and
     * exemptions have 4, and dividing by 100 adds 2, so a fee needs at most 16.
     */
    private const SCALE = 20;

    private const STANDARD_RATE = '0.30';

    public const VOLUME_RATE = '0.25';

    public const VOLUME_RATE_MINIMUM_PROPERTIES = 3;

    public const WAIVER_THRESHOLD = '10.00';

    public const FEE_CAP = '2500.00';

    private const NO_FEE = '0.00';

    /**
     * The fee rate for a client with this many properties enrolled in the season.
     */
    public function rateFor(int $enrolledCount): string
    {
        return $enrolledCount >= self::VOLUME_RATE_MINIMUM_PROPERTIES
            ? self::VOLUME_RATE
            : self::STANDARD_RATE;
    }

    /**
     * @param  list<array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}>  $jurisdictions
     *
     * @throws InvalidArgumentException when the data can't give a correct fee, rather than returning a wrong one.
     */
    public function calculate(string $noticedValue, string $finalValue, bool $homestead, array $jurisdictions, string $feeRate): FeeResult
    {
        $this->assertCalculable($noticedValue, $finalValue, $jurisdictions, $feeRate);

        $lines = array_map(
            fn (array $jurisdiction) => $this->taxLine($noticedValue, $finalValue, $homestead, $jurisdiction),
            $jurisdictions,
        );

        $exactSavings = bcsub(
            $this->sum(array_column($lines, 'tax_at_noticed')),
            $this->sum(array_column($lines, 'tax_at_final')),
            self::SCALE,
        );

        [$calculatedFee, $fee, $note] = $this->feeFor($exactSavings, $feeRate);

        return new FeeResult(
            exactSavings: $exactSavings,
            savings: $this->roundHalfUp($exactSavings),
            feeRate: $feeRate,
            calculatedFee: $calculatedFee,
            fee: $fee,
            note: $note,
            jurisdictions: $lines,
        );
    }

    /**
     * Rejects bad data up front. Without this, bcmath reads an empty string as zero
     * and the floor at $0 hides an oversized exemption, so both look like a real $0 fee.
     *
     * @param  list<array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}>  $jurisdictions
     */
    private function assertCalculable(string $noticedValue, string $finalValue, array $jurisdictions, string $feeRate): void
    {
        if ($jurisdictions === []) {
            throw new InvalidArgumentException('A property needs at least one jurisdiction.');
        }

        $this->assertDecimalString($noticedValue, 'Noticed value');
        $this->assertDecimalString($finalValue, 'Final value');
        $this->assertDecimalString($feeRate, 'Fee rate');

        foreach ($jurisdictions as $jurisdiction) {
            $this->assertDecimalString($jurisdiction['rate'], "{$jurisdiction['code']} rate");
            $this->assertValidExemption($jurisdiction);
        }
    }

    /**
     * @param  array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}  $jurisdiction
     */
    private function assertValidExemption(array $jurisdiction): void
    {
        $type = $jurisdiction['exemption_type'];
        $amount = $jurisdiction['exemption_amount'];

        if ($type === ExemptionType::None) {
            return;
        }

        $this->assertDecimalString($amount, "{$jurisdiction['code']} exemption amount");

        $isOverOneHundredPercent = $type === ExemptionType::Percent && bccomp($amount, '1', self::SCALE) > 0;

        if (bccomp($amount, '0', self::SCALE) < 0 || $isOverOneHundredPercent) {
            throw new InvalidArgumentException("{$jurisdiction['code']} exemption amount [{$amount}] is out of range.");
        }
    }

    /**
     * Takes mixed so PHP can't quietly convert a float or null to a string first.
     */
    private function assertDecimalString(mixed $value, string $label): void
    {
        if (! is_string($value) || ! preg_match('/\A-?\d+(\.\d+)?\z/', $value)) {
            throw new InvalidArgumentException("{$label} must be a decimal string.");
        }
    }

    /**
     * @param  array{code: string, rate: string, exemption_type: ExemptionType, exemption_amount: ?string}  $jurisdiction
     * @return array{code: string, rate: string, exemption_type: string, exemption_amount: ?string, taxable_at_noticed: string, tax_at_noticed: string, taxable_at_final: string, tax_at_final: string, savings: string}
     */
    private function taxLine(string $noticedValue, string $finalValue, bool $homestead, array $jurisdiction): array
    {
        $exemptionType = $homestead ? $jurisdiction['exemption_type'] : ExemptionType::None;
        $exemptionAmount = $exemptionType === ExemptionType::None ? null : $jurisdiction['exemption_amount'];

        $taxableAtNoticed = $this->taxableValue($noticedValue, $exemptionType, $exemptionAmount);
        $taxableAtFinal = $this->taxableValue($finalValue, $exemptionType, $exemptionAmount);
        $taxAtNoticed = $this->tax($taxableAtNoticed, $jurisdiction['rate']);
        $taxAtFinal = $this->tax($taxableAtFinal, $jurisdiction['rate']);

        return [
            'code' => $jurisdiction['code'],
            'rate' => $jurisdiction['rate'],
            'exemption_type' => $exemptionType->value,
            'exemption_amount' => $exemptionAmount,
            'taxable_at_noticed' => $taxableAtNoticed,
            'tax_at_noticed' => $taxAtNoticed,
            'taxable_at_final' => $taxableAtFinal,
            'tax_at_final' => $taxAtFinal,
            'savings' => bcsub($taxAtNoticed, $taxAtFinal, self::SCALE),
        ];
    }

    /**
     * The value left after the exemption, which can't go below zero.
     */
    private function taxableValue(string $value, ExemptionType $exemptionType, ?string $exemptionAmount): string
    {
        $taxable = match ($exemptionType) {
            ExemptionType::None => bcadd($value, '0', self::SCALE),
            ExemptionType::Flat => bcsub($value, $exemptionAmount, self::SCALE),
            ExemptionType::Percent => bcsub($value, bcmul($value, $exemptionAmount, self::SCALE), self::SCALE),
        };

        return bccomp($taxable, '0', self::SCALE) < 0
            ? bcadd('0', '0', self::SCALE)
            : $taxable;
    }

    private function tax(string $taxableValue, string $rate): string
    {
        return bcmul(bcdiv($taxableValue, '100', self::SCALE), $rate, self::SCALE);
    }

    /**
     * @param  list<string>  $amounts
     */
    private function sum(array $amounts): string
    {
        return array_reduce(
            $amounts,
            fn (string $total, string $amount) => bcadd($total, $amount, self::SCALE),
            '0',
        );
    }

    /**
     * Applies the fee rules in order: no savings, percentage, round, waiver, cap.
     *
     * @return array{string, string, ?FeeNote} The fee before the waiver and cap, the final fee, and why they differ.
     */
    private function feeFor(string $exactSavings, string $feeRate): array
    {
        if (bccomp($exactSavings, '0', self::SCALE) <= 0) {
            return [self::NO_FEE, self::NO_FEE, FeeNote::NoSavings];
        }

        $calculatedFee = $this->roundHalfUp(bcmul($exactSavings, $feeRate, self::SCALE));

        if (bccomp($calculatedFee, self::WAIVER_THRESHOLD, 2) < 0) {
            return [$calculatedFee, self::NO_FEE, FeeNote::Waived];
        }

        if (bccomp($calculatedFee, self::FEE_CAP, 2) > 0) {
            return [$calculatedFee, self::FEE_CAP, FeeNote::Capped];
        }

        return [$calculatedFee, $calculatedFee, null];
    }

    /**
     * Rounds to the cent. bcmath truncates toward zero, so moving half a cent away
     * from zero first gives half-up rounding, in either direction.
     */
    private function roundHalfUp(string $amount): string
    {
        return bccomp($amount, '0', self::SCALE) < 0
            ? bcsub($amount, '0.005', 2)
            : bcadd($amount, '0.005', 2);
    }
}
