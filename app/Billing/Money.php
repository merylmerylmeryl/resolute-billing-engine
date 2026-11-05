<?php

namespace App\Billing;

/**
 * Display formatting for money and rates, using string operations only (bcmath and
 * regex), so a value never passes through a float. Nothing here changes what is stored.
 */
class Money
{
    /**
     * Rounds half away from zero to 2 decimals and adds thousands commas and a dollar sign (after any minus sign), so
     * "2850.000000000000" becomes "$2,850.00". bcmath truncates, so half a cent is
     * added first (or subtracted, for a negative value).
     */
    public static function format(string $value): string
    {
        $negative = bccomp($value, '0', 20) < 0;
        $rounded = $negative ? bcsub($value, '0.005', 2) : bcadd($value, '0.005', 2);

        if (bccomp($rounded, '0', 2) === 0) {
            $rounded = '0.00';
            $negative = false;
        }

        [$whole, $cents] = explode('.', ltrim($rounded, '-'));
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-' : '').'$'.$whole.'.'.$cents;
    }

    /**
     * What a client owes on an adjustment, in words rather than a +/- sign, which a
     * client can misread as a refund. A negative amount reads "Credit $26.10".
     */
    public static function amountDue(string $amount): string
    {
        $formatted = self::format($amount);

        return str_starts_with($formatted, '-') ? 'Credit '.substr($formatted, 1) : $formatted;
    }

    /**
     * The money amount without its minus sign, for a figure that has a label above it.
     */
    public static function absolute(string $amount): string
    {
        return ltrim(self::format($amount), '-');
    }

    /**
     * The small label above an invoice total: "Amount due" for an invoice, and for an
     * adjustment "Additional amount due" or "Credit".
     */
    public static function totalLabel(string $total, bool $isAdjustment): string
    {
        $formatted = self::format($total);

        return match (true) {
            ! $isAdjustment, $formatted === '$0.00' => 'Amount due',
            str_starts_with($formatted, '-') => 'Credit',
            default => 'Additional amount due',
        };
    }

    /**
     * For property values and taxable values, which are usually whole dollars: drops the
     * cents when they are .00 ("$4,000,000") and keeps them otherwise ("$4,000,000.50").
     */
    public static function value(string $value): string
    {
        return preg_replace('/\.00$/', '', self::format($value));
    }

    /**
     * Shows a stored rate such as "0.3000" as "30%", and "0.2750" as "27.5%".
     */
    public static function percent(string $rate): string
    {
        return self::trimZeros(bcmul($rate, '100', 4)).'%';
    }

    /**
     * A jurisdiction rate without trailing zeros: "0.9500" becomes "0.95".
     */
    public static function rate(string $rate): string
    {
        return self::trimZeros($rate);
    }

    private static function trimZeros(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
