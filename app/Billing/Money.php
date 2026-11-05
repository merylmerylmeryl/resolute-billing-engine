<?php

namespace App\Billing;

/**
 * Display formatting for money and rates, using string operations only (bcmath and
 * regex), so a value never passes through a float. Nothing here changes what is stored.
 */
class Money
{
    /**
     * Rounds half away from zero to 2 decimals and adds thousands commas, so
     * "2850.000000000000" becomes "2,850.00". bcmath truncates, so half a cent is
     * added first (or subtracted, for a negative value).
     */
    public static function format(string $value, bool $showPlus = false): string
    {
        $negative = bccomp($value, '0', 20) < 0;
        $rounded = $negative ? bcsub($value, '0.005', 2) : bcadd($value, '0.005', 2);

        if (bccomp($rounded, '0', 2) === 0) {
            $rounded = '0.00';
            $negative = false;
        }

        [$whole, $cents] = explode('.', ltrim($rounded, '-'));
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $sign = $negative ? '-' : ($showPlus && $rounded !== '0.00' ? '+' : '');

        return $sign.$whole.'.'.$cents;
    }

    /**
     * Shows a stored rate such as "0.3000" as "30%", and "0.2750" as "27.5%".
     */
    public static function percent(string $rate): string
    {
        $percent = bcmul($rate, '100', 4);

        return rtrim(rtrim($percent, '0'), '.').'%';
    }
}
