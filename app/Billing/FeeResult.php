<?php

namespace App\Billing;

use App\Enums\FeeNote;

/**
 * The outcome of one fee calculation. Every money value is a string, never a float.
 *
 * `exactSavings` and the per-jurisdiction values are unrounded. `savings` is rounded
 * for display only, so a fee must never be calculated from it.
 */
readonly class FeeResult
{
    /**
     * @param  list<array{code: string, rate: string, exemption_type: string, exemption_amount: ?string, taxable_at_noticed: string, tax_at_noticed: string, taxable_at_final: string, tax_at_final: string, savings: string}>  $jurisdictions
     */
    public function __construct(
        public string $exactSavings,
        public string $savings,
        public string $feeRate,
        public string $calculatedFee,
        public string $fee,
        public ?FeeNote $note,
        public array $jurisdictions,
    ) {}

    /**
     * The snapshot stored in an invoice line's `breakdown` column.
     *
     * @return array{exact_savings: string, calculated_fee: string, note: ?string, jurisdictions: list<array{code: string, rate: string, exemption_type: string, exemption_amount: ?string, taxable_at_noticed: string, tax_at_noticed: string, taxable_at_final: string, tax_at_final: string, savings: string}>}
     */
    public function toBreakdown(): array
    {
        return [
            'exact_savings' => $this->exactSavings,
            'calculated_fee' => $this->calculatedFee,
            'note' => $this->note?->value,
            'jurisdictions' => $this->jurisdictions,
        ];
    }
}
