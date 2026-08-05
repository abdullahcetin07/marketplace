<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Support;

use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Models\SettlementWindow;

/**
 * What a seller is owed, and how much of it they may actually have yet
 * (ADR-062 + ADR-064, Payment.md §7–§8).
 *
 * **THE NUMBER SPLIT IN TWO WHEN SHIPPING ARRIVED.** Until S3 a balance was one
 * figure and a payout could draw the whole of it. But a seller must not be paid
 * for goods the buyer can still send back — that is the entire reason payout waits
 * on DELIVERY rather than on payment (Shipping.md §4) — so what is owed and what
 * is payable are now different questions with different answers:
 *
 *   BALANCE  Σ of every ledger entry. Unchanged, still the truth about what the
 *            platform owes, still computed on read and never stored.
 *   ON HOLD  the net of orders that have not been delivered long enough. Money
 *            that is genuinely the seller's and simply not payable yet.
 *   PAYABLE  balance − on hold. The ceiling `CreatePayoutAction` enforces.
 *
 * **AN ENTRY WITH NO `order_uuid` IS NEVER HELD BACK**, and that is a deliberate
 * rule rather than an accident of the query. A ledger row that names no order
 * cannot be tied to a delivery — it is an adjustment, a correction, a payout
 * reversal — and holding it hostage to a parcel that does not exist would freeze a
 * seller's money forever with nothing that could ever release it.
 *
 * IT IS THREE QUERIES, NOT A JOIN, and deliberately so: the held-back set is
 * "orders with ledger entries MINUS orders whose window has opened", and expressing
 * that as an outer join with a null-or-future predicate is the kind of SQL that is
 * correct on the day it is written and misread every time afterwards. The sets are
 * bounded by one seller's own orders.
 *
 * @see App\Modules\Payment\Application\Actions\CreatePayoutAction
 * @see docs/modules/Payment.md §8
 */
final readonly class SellerBalance
{
    private function __construct(
        public int $balanceMinor,
        public int $onHoldMinor,
        public int $payableMinor,
    ) {}

    /**
     * The three numbers, computed together so they cannot disagree.
     *
     * COMPUTED TOGETHER FOR A REASON: a screen that read the balance from one
     * place and the payable amount from another would eventually show a seller a
     * figure the payout then refused, which is the single most annoying way for a
     * finance feature to be wrong.
     */
    public static function for(string $sellerOrgUuid): self
    {
        $balance = SellerLedgerEntry::balanceFor($sellerOrgUuid);
        $onHold = self::onHoldFor($sellerOrgUuid);

        return new self(
            balanceMinor: $balance,
            onHoldMinor: $onHold,
            /*
            | NEVER BELOW ZERO. A refund can drive the balance negative
            | (Payment.md §8) while an undelivered order still holds money back;
            | the two together could otherwise produce a "payable" figure smaller
            | than the negative balance, which means nothing. Clamping says the
            | honest thing: there is nothing to pay.
            */
            payableMinor: max(0, $balance - $onHold),
        );
    }

    /**
     * The net of orders whose delivery hold has not expired.
     *
     * ORDERS WITHOUT A WINDOW COUNT AS HELD, which is the conservative half of
     * this method and the important one: an order that has been paid but never
     * delivered has no window row at all, and treating "no window" as "payable"
     * would pay a seller the moment the card cleared — exactly the behaviour
     * ADR-064 exists to prevent.
     */
    private static function onHoldFor(string $sellerOrgUuid): int
    {
        /** @var array<string, int> $byOrder */
        $byOrder = SellerLedgerEntry::query()
            ->where('seller_org_uuid', $sellerOrgUuid)
            ->whereNotNull('order_uuid')
            ->groupBy('order_uuid')
            ->selectRaw('order_uuid, SUM(amount_minor) as net')
            ->pluck('net', 'order_uuid')
            ->map(static fn (mixed $net): int => (int) $net)
            ->all();

        if ($byOrder === []) {
            return 0;
        }

        $payable = SettlementWindow::query()
            ->forSeller($sellerOrgUuid)
            ->payable()
            ->whereIn('order_uuid', array_keys($byOrder))
            ->pluck('order_uuid')
            ->all();

        $onHold = 0;

        foreach ($byOrder as $orderUuid => $net) {
            if (in_array($orderUuid, $payable, true)) {
                continue;
            }

            /*
            | ONLY POSITIVE NETS ARE HELD. A refunded order nets NEGATIVE — the
            | sale credit reversed plus the commission given back — and "holding
            | back" a negative would ADD to what the seller may draw, paying them
            | extra for a return. A debt is not withheld; it is simply owed.
            */
            $onHold += max(0, $net);
        }

        return $onHold;
    }
}
