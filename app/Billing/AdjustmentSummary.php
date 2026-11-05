<?php

namespace App\Billing;

use App\Models\InvoiceLine;

/**
 * Explains an adjustment in one plain sentence, built from the line it corrects and the
 * new line, so every number in it can be traced to a stored line.
 */
class AdjustmentSummary
{
    /**
     * @param  InvoiceLine  $previous  The line this one is measured against (the latest line before it).
     */
    public static function describe(InvoiceLine $previous, InvoiceLine $line): string
    {
        $changes = [];

        if (bccomp($previous->final_value, $line->final_value, 2) !== 0) {
            $changes[] = 'final value revised from '.Money::value($previous->final_value).' to '.Money::value($line->final_value);
        }

        if (bccomp($previous->noticed_value, $line->noticed_value, 2) !== 0) {
            $changes[] = 'county value revised from '.Money::value($previous->noticed_value).' to '.Money::value($line->noticed_value);
        }

        $opening = $changes === [] ? 'Tax rates or exemptions changed' : ucfirst(implode(' and ', $changes));

        $savingsDirection = bccomp($line->savings, $previous->savings, 2);
        $feeDirection = bccomp($line->fee, $previous->fee, 2);

        $savings = $savingsDirection === 0
            ? 'tax savings stayed at '.Money::format($line->savings)
            : 'tax savings '.self::movement($savingsDirection, $previous->savings, $line->savings);

        $fee = $feeDirection === 0
            ? 'the fee stayed at '.Money::format($line->fee)
            : 'the fee '.($feeDirection === $savingsDirection ? '' : self::verb($feeDirection).' ').'from '.Money::format($previous->fee).' to '.Money::format($line->fee);

        return $opening.', so '.$savings.' and '.$fee.'.';
    }

    private static function movement(int $direction, string $from, string $to): string
    {
        return self::verb($direction).' from '.Money::format($from).' to '.Money::format($to);
    }

    private static function verb(int $direction): string
    {
        return $direction > 0 ? 'rose' : 'fell';
    }
}
