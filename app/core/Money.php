<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Money helpers without floating point.
 *
 * Amounts travel as DECIMAL strings ("1299.50", exactly what MySQL
 * DECIMAL returns) or as integer cents (129950). Maths is done in
 * integer cents only; floats are never used for money.
 */
final class Money
{
    /** Largest amount accepted from forms: 9,999,999.99 */
    public const MAX_CENTS = 999_999_999;

    /**
     * Normalise user input to a DECIMAL string, or null if invalid.
     * Accepts "1299", "1,299.5", " 1299.50 ". Rejects negatives,
     * exponents, more than 2 decimals and anything non-numeric.
     */
    public static function parse(string $input): ?string
    {
        $value = str_replace([',', ' ', "\u{00A0}"], '', trim($input));
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        return self::fromCents($cents);
    }

    /** DECIMAL string (or int cents) -> int cents. */
    public static function toCents(string|int|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount;
        }
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/', trim($amount), $m)) {
            throw new \InvalidArgumentException("Not a decimal amount: {$amount}");
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    /** int cents -> DECIMAL string "1299.50" */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "৳1,299.50" */
    public static function format(string|int|null $amount, ?string $symbol = null): string
    {
        $cents = self::toCents($amount);
        $symbol ??= (string) config('app.store.currency_symbol', '');
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        $whole = (string) intdiv($cents, 100);
        $grouped = ltrim(strrev(implode(',', str_split(strrev($whole), 3))), ',');

        return $sign . $symbol . $grouped . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * True when a sale price applies: set, above zero and below regular.
     * Mirrors the generated `products.price` column.
     */
    public static function isOnSale(string|int|null $regular, string|int|null $sale): bool
    {
        $s = self::toCents($sale);
        return $s > 0 && $s < self::toCents($regular);
    }

    /** Whole-percent discount, rounded half up, integer maths only. */
    public static function discountPercent(string|int|null $regular, string|int|null $sale): ?int
    {
        if (!self::isOnSale($regular, $sale)) {
            return null;
        }
        $r = self::toCents($regular);
        $s = self::toCents($sale);
        return intdiv(($r - $s) * 100 * 2 + $r, $r * 2);
    }
}
