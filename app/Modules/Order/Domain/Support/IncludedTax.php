<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Support;

/**
 * Pull the KDV out of a tax-INCLUDED total (§3.4, ADR-055).
 *
 * PRICES ON THIS PLATFORM INCLUDE TAX (ADR-042). So the arithmetic is extraction,
 * not addition — `line_tax = line_total − line_total / (1 + rate)` — and getting
 * that backwards inflates every order by the rate.
 *
 * IT NEVER TOUCHES A FLOAT WITH AN AMOUNT IN IT. The rate arrives as a decimal
 * STRING because the column is DECIMAL (ADR-005), is scaled to an integer once,
 * and the whole division then runs in integers:
 *
 *     net = round(total × 10⁴ ÷ (10⁴ + scaledRate))
 *     tax = total − net
 *
 * The one float in the file multiplies a four-decimal literal by 10 000, which is
 * exact in IEEE 754 at this magnitude and is not money. Doing the division in
 * floating point instead would put every order total one rounding error away from
 * an invoice that does not add up.
 *
 * TAX IS COMPUTED PER LINE, NEVER ON AN ORDER TOTAL, and that is a correctness
 * decision rather than a stylistic one: lines carry DIFFERENT rates — a %1 book
 * beside a %20 kettle — so an order-level extraction has no single rate to use.
 * The two also differ by a kuruş even when the rates match, and the per-line
 * figure is the one an invoice has to show.
 *
 * A PURE DOMAIN HELPER: no Eloquent, no container, no config (ADR-019). It is a
 * function that happens to need a namespace.
 *
 * @see docs/modules/Order.md §3.4
 */
final class IncludedTax
{
    /**
     * The scale `tax_rate` is stored at — `decimal(6,4)`, so 0.2000 is 2 000.
     *
     * Mirrors `TaxRate::SCALE` in Catalog by value rather than by import: Order
     * imports no module (ADR-052…056), and a rate crosses the boundary as a
     * string at this scale. If Catalog ever changed its column's precision, this
     * constant is where the contract between them is written down.
     */
    public const int SCALE = 10_000;

    /**
     * The KDV inside `$totalMinor` at `$rate` (a ratio, e.g. `"0.2000"`).
     *
     * A zero or empty rate yields zero tax rather than dividing by one and
     * returning zero the long way — %0 is a real bracket (exports, exempt goods),
     * not a missing value.
     */
    public static function of(int $totalMinor, string $rate): int
    {
        $scaled = self::scale($rate);

        if ($scaled <= 0 || $totalMinor === 0) {
            return 0;
        }

        $net = (int) round($totalMinor * self::SCALE / (self::SCALE + $scaled));

        return $totalMinor - $net;
    }

    /**
     * The rate as an integer at `self::SCALE` — `"0.2000"` → 2000.
     */
    public static function scale(string $rate): int
    {
        return (int) round(((float) $rate) * self::SCALE);
    }
}
