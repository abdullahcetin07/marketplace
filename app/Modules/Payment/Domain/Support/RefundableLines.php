<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Support;

use App\Modules\Payment\Domain\Models\PaymentRefundLine;

/**
 * What a line-level refund comes to, and whether it is allowed at all (S4).
 *
 * **THE MONEY IS PROPORTIONAL AND THE ARITHMETIC IS INTEGER.** A buyer returning
 * one of the two they bought gets back one unit's KDV-inclusive price, and the
 * seller gets back one unit's share of the commission the platform took. Both are
 * computed here, once, in kuruş — no float is constructed anywhere in the chain
 * (ADR-005).
 *
 * **THE KDV NEEDS NO SEPARATE CALCULATION, AND THAT IS THE PART WORTH READING.**
 * Turkish retail prices are KDV-INCLUSIVE (ADR-055): `unit_price_minor` already
 * contains the tax, and `line_total_minor` is the gross the buyer actually paid.
 * So refunding `unit_price × qty` refunds the tax with it, in exactly the
 * proportion it was charged. A separate "proportional KDV" term would be adding
 * the tax a second time.
 *
 * THE LAST UNIT TAKES THE REMAINDER. `line_total` is not always `unit_price ×
 * quantity` to the kuruş once a rounding has happened upstream, and refunding
 * `unit_price` per unit would leave a stray kuruş stranded on a fully returned
 * line forever. So a refund that empties a line is billed as "everything not yet
 * refunded" rather than as a multiplication — the same trick a well-behaved
 * instalment plan uses for its final payment.
 *
 * COMMISSION IS THE FROZEN FIGURE SCALED, never the rules re-resolved (ADR-061).
 * Re-running the resolver would silently apply today's rates to last month's sale,
 * and the two computations would disagree by a kuruş on exactly the orders anybody
 * bothered to check.
 *
 * @see App\Modules\Payment\Application\Actions\RefundLinesAction
 */
final readonly class RefundableLines
{
    private function __construct(
        public string $orderLineUuid,
        public string $variantUuid,
        public int $quantity,
        public int $amountMinor,
        public int $commissionMinor,
    ) {}

    /**
     * Price a return of `$quantity` units of one order line.
     *
     * Null when nothing may be refunded — the line has no units left, or the
     * caller asked for none.
     *
     * @param array{id: string, variant_uuid: string, quantity: int, unit_price_minor: int, line_total_minor: int, commission_minor: int|null} $line
     */
    public static function price(array $line, int $quantity): ?self
    {
        $alreadyRefunded = PaymentRefundLine::refundedQuantityFor($line['id']);
        $remaining = max(0, $line['quantity'] - $alreadyRefunded);

        /*
        | THE GUARD THAT REPLACED P5'S UNIQUE INDEX. A line may go back up to its
        | REMAINING quantity and no further — asking for three of two returns
        | nothing rather than two, because a caller that miscounted should be
        | refused rather than quietly given a different answer than it asked for.
        */
        if ($quantity <= 0 || $quantity > $remaining) {
            return null;
        }

        $refundedSoFar = (int) PaymentRefundLine::query()
            ->where('order_line_uuid', $line['id'])
            ->sum('amount_minor');

        $commissionRefundedSoFar = (int) PaymentRefundLine::query()
            ->where('order_line_uuid', $line['id'])
            ->sum('commission_minor');

        $isLastOfTheLine = $quantity === $remaining;

        /*
        | THE LAST UNIT TAKES THE REMAINDER. @see the class docblock — otherwise a
        | rounding upstream strands a kuruş on a fully returned line forever.
        */
        $amount = $isLastOfTheLine
            ? max(0, $line['line_total_minor'] - $refundedSoFar)
            : $line['unit_price_minor'] * $quantity;

        $frozenCommission = $line['commission_minor'] ?? 0;

        $commission = $isLastOfTheLine
            ? max(0, $frozenCommission - $commissionRefundedSoFar)
            : self::share($frozenCommission, $quantity, $line['quantity']);

        return new self(
            orderLineUuid: $line['id'],
            variantUuid: $line['variant_uuid'],
            quantity: $quantity,
            amountMinor: $amount,
            commissionMinor: $commission,
        );
    }

    /**
     * `$total × $part / $whole`, half-up, entirely in integers.
     *
     * NO FLOAT, EVER. `(int) round($total * $part / $whole)` would build one, and
     * a commission is money — the rule the whole platform keeps (ADR-005). Integer
     * division plus a doubled remainder is the same answer without the binary
     * representation.
     */
    private static function share(int $total, int $part, int $whole): int
    {
        if ($whole <= 0) {
            return 0;
        }

        $quotient = intdiv($total * $part, $whole);
        $remainder = ($total * $part) % $whole;

        // Half-up: the same rounding `CommissionAmount` uses on the way in, so a
        // charge and its reversal cannot disagree by a kuruş.
        return $remainder * 2 >= $whole ? $quotient + 1 : $quotient;
    }
}
