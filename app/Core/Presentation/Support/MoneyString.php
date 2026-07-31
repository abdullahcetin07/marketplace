<?php

declare(strict_types=1);

namespace App\Core\Presentation\Support;

/**
 * Integer minor units → the decimal string an API returns (005 §28, ADR-005).
 *
 * `"1299.90"`, never `1299.9` and never `1299.90` as a JSON number: most clients
 * parse a JSON number as a float, which reintroduces exactly the precision loss
 * integer storage exists to prevent. A string crosses the wire intact and the
 * client decides what to do with it.
 *
 * THE ARITHMETIC IS STRING ARITHMETIC, not `$minor / 100`. Dividing produces a
 * float, and a float is the thing this whole convention exists to avoid —
 * `number_format(0.1 + 0.2, 2)` is the classic demonstration. Padding and
 * slicing the digits cannot drift, at any magnitude, for any number of decimal
 * places.
 *
 * IN CORE BECAUSE A SECOND MODULE NEEDED IT, which is exactly the condition its
 * previous home in `Offer\Presentation\Support` said would move it. Order renders
 * order lines and totals as decimal strings and may not import Offer
 * (`LayeringTest`), so the choice was Core or a copy — and a second copy of money
 * formatting is the kind of duplication that ends with two endpoints disagreeing
 * about a kuruş.
 *
 * It waited for that second caller rather than being promoted on principle:
 * guessing at a shared shape from one caller is how Core fills up with
 * almost-right abstractions.
 *
 * Presentation-only. Storage stays integer minor units (non-negotiable #6); this
 * is the last thing that happens to an amount before it leaves the application.
 */
final class MoneyString
{
    /**
     * @param  int  $decimals  the currency's own `decimal_places`
     */
    public static function from(int $minorAmount, int $decimals = 2): string
    {
        $decimals = max(0, $decimals);
        $sign = $minorAmount < 0 ? '-' : '';
        $digits = (string) abs($minorAmount);

        if ($decimals === 0) {
            return $sign.$digits;
        }

        // Pad to at least one integer digit plus the fractional part, so 5
        // kuruş renders "0.05" rather than "".05" or ".5".
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }
}
