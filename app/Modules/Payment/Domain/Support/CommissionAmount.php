<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Support;

/**
 * `rate × base`, in kuruş, rounded one way only (ADR-061, Payment.md §6).
 *
 * ONE PLACE, ONE ROUNDING RULE. Commission is computed per line and will be
 * reversed per line on a refund (P5); if those two disagreed by a kuruş the
 * seller's balance would drift by a kuruş per refunded line, forever, with nothing
 * to reconcile it against. So there is exactly one function, and both directions
 * call it.
 *
 * HALF-UP, AND THE CHOICE IS ARBITRARY BUT THE CONSISTENCY IS NOT. PHP's `round()`
 * is half-away-from-zero, which for the non-negative amounts here is half-up.
 * Banker's rounding would be defensible too; what is not defensible is one rule at
 * charge time and another at refund time.
 *
 * IT NEVER TOUCHES A FLOAT WITH AN AMOUNT IN IT — the same discipline as
 * `IncludedTax` in Order. The rate arrives as a decimal STRING (the column is
 * DECIMAL, ADR-005), is scaled to an integer once, and the multiplication then
 * runs in integers:
 *
 *     commission = round(base × scaledRate ÷ 10⁴)
 *
 * The one float multiplies a four-decimal literal by 10 000, which is exact in
 * IEEE 754 at this magnitude and is not money. Multiplying kuruş by a float rate
 * instead would put every seller's balance one rounding error away from a number
 * they can reproduce.
 *
 * THE BASE IS KDV-INCLUSIVE (owner choice, 2026-08-04): the gross the buyer paid,
 * not the net of tax. That is a business decision recorded in Payment.md §6, and
 * it is stated here because this function cannot tell the difference and the next
 * reader will wonder.
 *
 * A PURE DOMAIN HELPER: no Eloquent, no container, no config (ADR-019).
 *
 * @see docs/modules/Payment.md §6
 */
final class CommissionAmount
{
    /**
     * The scale `commission_rules.rate` is stored at — `decimal(6,4)`, so 0.1500
     * is 1 500.
     *
     * Mirrors `IncludedTax::SCALE` by value rather than by import: Payment imports
     * no module, and a rate crosses a boundary as a string at this scale.
     */
    public const int SCALE = 10_000;

    /**
     * The commission on `$baseMinor` at `$rate` (a ratio, e.g. `"0.1500"`).
     *
     * A zero, empty or negative rate yields zero rather than a negative
     * commission: %0 is a real arrangement — a launch promotion, a strategic
     * seller — and the platform paying the seller extra is not.
     */
    public static function of(int $baseMinor, string $rate): int
    {
        $scaled = self::scale($rate);

        if ($scaled <= 0 || $baseMinor <= 0) {
            return 0;
        }

        return (int) round($baseMinor * $scaled / self::SCALE);
    }

    /**
     * The rate as an integer at `self::SCALE` — `"0.1500"` → 1500.
     */
    public static function scale(string $rate): int
    {
        return (int) round(((float) $rate) * self::SCALE);
    }
}
