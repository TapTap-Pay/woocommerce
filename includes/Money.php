<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Decimal ↔ minor-units conversion, ISO-4217 aware. The TapTap API
 * (and every payments processor on earth) speaks integer minor units —
 * mixing floats and money is how rounding errors quietly creep into
 * settlement reconciliation.
 */
final class Money
{
    /**
     * ISO 4217 currencies with zero decimal places. Anything not in
     * this list is treated as 2 decimals. Three-decimal currencies
     * (KWD, BHD, TND, OMR, JOD, IQD) are rare enough that supporting
     * them via WooCommerce is currently out of scope — log a gap if
     * a merchant needs them.
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private function __construct()
    {
    }

    public static function to_minor(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    public static function from_minor(int $minor, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL, true)) {
            return (float) $minor;
        }
        return $minor / 100.0;
    }
}
