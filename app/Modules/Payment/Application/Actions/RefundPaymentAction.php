<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Core\Domain\Contracts\LoyaltyContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Events\PaymentRefunded;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give the money back, and everything that followed it (Payment.md §8, P5).
 *
 * FOUR THINGS MOVE, IN THIS ORDER, and the order is the design:
 *
 *   1. **The PSP first.** Nothing is written until PayTR has actually agreed to
 *      send the money back. Writing the ledger first and calling the gateway
 *      afterwards would leave a seller debited for a refund that never happened —
 *      and unlike a payment, there is no callback coming later to correct it.
 *   2. **The refund rows**, whose unique `(payment, order)` index is what makes a
 *      second click a refusal instead of a second debit.
 *   3. **The seller's ledger**: `refund_debit` takes back what the sale credited,
 *      `refund_commission_credit` gives back the commission the platform took. A
 *      refund the platform keeps its cut on is a refund the seller pays for.
 *   4. **The stock**, restocked through Inventory's command port — the mirror of
 *      the commit in §5, and the reason `RestockAction` exists (Order.md §12.5).
 *
 * IT REFUNDS ORDERS, NOT AMOUNTS. `partially_refunded` on this platform means
 * "some of the sellers' orders in this basket" — @see `RefundRequestDTO` for why
 * an arbitrary lira figure could not say which seller, which commission or which
 * units it referred to.
 *
 * THE GATEWAY CALL IS INSIDE THE TRANSACTION, and that is a deliberate exception
 * to this codebase's usual "side effects in `after()`" rule — the same exception
 * `InitiatePaymentAction` makes. A refund is not a side effect of the decision; it
 * IS the decision, and everything written here is only true because the PSP said
 * yes. Its cost, stated: a slow PSP holds a transaction open. Bounded because the
 * rows involved are this payment's own and nothing else contends for them.
 *
 * A FAILED RESTOCK DOES NOT UNDO A REFUND, for the same reason a failed commit
 * does not undo a payment: the money is real. It is logged for a human, and
 * Inventory's movement ledger is where the gap shows.
 *
 * IT IMPORTS NO MODULE. Orders arrive through `OrderQueryContract`, stock moves
 * through `InventoryReservationContract`, and the orders' own statuses are moved
 * by Order's class-string listener on `PaymentRefunded`.
 *
 * @see docs/modules/Payment.md §8
 */
final class RefundPaymentAction extends BaseAction
{
    private ?PaymentRefunded $refunded = null;

    /**
     * Held between the gateway call and the rows it justifies.
     */
    private ?string $providerReference = null;

    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly OrderQueryContract $orders,
        private readonly InventoryReservationContract $reservations,
        /* The Core redemption port (ADR-084) — Payment drives it, as it does
        | the hold and the commit. */
        private readonly LoyaltyContract $loyalty,
    ) {}

    public function handle(mixed ...$arguments): Payment
    {
        /** @var RefundRequestDTO $request */
        $request = $arguments[0];

        $payment = Payment::query()
            ->with('currency')
            ->where('uuid', $request->paymentUuid)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            throw PaymentException::groupNotFound($request->paymentUuid);
        }

        if (! $payment->status->isSettled()) {
            // Nothing was ever collected. Asking the PSP to reverse it would be
            // asking about a transaction it does not have.
            throw PaymentException::notRefundable($payment->uuid, $payment->status->value);
        }

        $targets = $this->targets($payment, $request->orderUuids);

        if ($targets === []) {
            throw PaymentException::nothingToRefund($payment->uuid);
        }

        $amountMinor = (int) array_sum(array_column($targets, 'amount_minor'));

        if ($amountMinor <= 0) {
            throw PaymentException::nothingToRefund($payment->uuid);
        }

        $this->reverseAtGateway($payment, $amountMinor);

        foreach ($targets as $target) {
            $this->record($payment, $target, $request);
            $this->reverseLedger($payment, $target);
            $this->restock($payment, (string) $target['order_uuid']);
        }

        $refundedTotal = PaymentRefund::refundedMinorFor((int) $payment->getKey());
        /*
        | **"FULLY REFUNDED" MEANS THE WHOLE BASKET CAME BACK, AND THE BASKET IS
        | CARD + POINTS** (ADR-084). `amount_minor` alone is the CARD charge, which
        | is zero when points covered everything — so the first partial refund of a
        | points-funded order would compare against zero, call itself full, and
        | re-credit every point the customer spent.
        |
        | Found while fixing the 500 this same path used to throw: the gateway
        | rejection was hiding it, and skipping the gateway would have turned a
        | loud failure into a quiet overpayment.
        */
        $settledMinor = $payment->amount_minor + $payment->discount_minor;
        $fully = $refundedTotal >= $settledMinor;

        $payment->forceFill([
            'status' => $fully ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            'refunded_at' => now(),
        ])->save();

        /*
        | **THE POINTS COME BACK WITH THE MONEY** (ADR-084). The customer ends
        | whole: the card is refunded through PayTR as it always was, and what they
        | spent in points is re-credited as a new positive row — the ledger is
        | append-only, so a reversal is written rather than the spend erased.
        |
        | **THE FRACTION IS COMPUTED HERE BECAUSE ONLY PAYMENT HOLDS BOTH NUMBERS.**
        | The basket the customer actually settled is the card charge PLUS the
        | discount their points bought; refunding half of it must return half the
        | points. Loyalty is handed the proportion rather than the amounts, so it
        | never learns what a payment is.
        */
        $this->loyalty->reverse(
            $payment->checkout_group_uuid,
            'refund',
            $this->refundedFraction($payment, $amountMinor, $fully),
        );

        $this->refunded = new PaymentRefunded(
            paymentUuid: $payment->uuid,
            checkoutGroupUuid: $payment->checkout_group_uuid,
            amountMinor: $amountMinor,
            currencyCode: $payment->currency->code,
            orderUuids: array_map(static fn (array $target): string => (string) $target['order_uuid'], $targets),
            fullyRefunded: $fully,
        );

        return $payment;
    }

    /**
     * The event, dispatched AFTER COMMIT — so Order cannot mark an order refunded
     * on the strength of a transaction that then rolls back.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->refunded !== null) {
            event($this->refunded);
        }
    }

    /**
     * Which orders this refund actually covers, with what each is worth.
     *
     * ALREADY-REFUNDED ORDERS ARE FILTERED OUT RATHER THAN REFUSED, so refunding
     * a basket where one seller's order came back last week refunds the rest
     * instead of failing on the one. Refunding a basket where NOTHING is left is
     * the refusal — that is `nothingToRefund`.
     *
     * AN ORDER THAT IS NOT IN THIS PAYMENT'S GROUP IS SILENTLY ABSENT, for the
     * same reason every other public surface answers "not found" the same way for
     * "not yours" and "does not exist": the alternative lets a caller discover
     * which order uuids are real by watching which ones change the error.
     *
     * @param array<int, string> $requested
     *
     * @return array<int, array{order_uuid: string, seller_org_uuid: string, amount_minor: int, commission_minor: int}>
     */
    private function targets(Payment $payment, array $requested): array
    {
        $inGroup = $this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid);

        $wanted = $requested === []
            ? $inGroup
            : array_values(array_intersect($inGroup, $requested));

        $alreadyRefunded = PaymentRefund::query()
            ->forPayment((int) $payment->getKey())
            ->pluck('order_uuid')
            ->all();

        $targets = [];

        foreach ($wanted as $orderUuid) {
            if (in_array($orderUuid, $alreadyRefunded, true)) {
                continue;
            }

            $settlement = $this->orders->orderSettlement($orderUuid);
            $totals = $this->orders->orderTotals($orderUuid);

            if ($settlement === null || $totals === null) {
                Log::channel('errors')->error('Cannot refund an order that does not resolve', [
                    'payment_uuid' => $payment->uuid,
                    'order_uuid' => $orderUuid,
                ]);

                continue;
            }

            $targets[] = [
                'order_uuid' => $orderUuid,
                'seller_org_uuid' => $settlement['selling_org_uuid'],
                // KDV-INCLUSIVE, the gross the buyer actually paid — the same base
                // the sale credited and the same one the commission was taken on
                // (ADR-061).
                'amount_minor' => $totals['grand_total_minor'],
                /*
                | NULL MEANS UNSETTLED, NOT ZERO. If the commission was never
                | frozen, the seller was never credited either — so there is
                | nothing to reverse and giving back a zero commission is the
                | honest entry. @see `CreditSellerLedger`, which refuses the same
                | value on the way in.
                */
                'commission_minor' => (int) ($settlement['commission_minor'] ?? 0),
            ];
        }

        return $targets;
    }

    /**
     * Ask the PSP to send the money back. Nothing is written unless it agrees.
     */
    /**
     * The CARD's share of a refund, in minor units (ADR-084).
     *
     * **THE GOODS VALUE IS NOT THE CARD VALUE ON A MIXED BASKET.** ₺10 of shopping
     * settled with ₺5 of card and ₺5 of points: refunding one ₺5 unit returns
     * ₺2.50 to the card and 50 points, not ₺5 to the card. Sending the goods value
     * asked PayTR to refund ₺5.00 against a ₺5.00 charge and it answered *"iade
     * yapilamiyor"* — and had it agreed, the customer would have been handed back
     * more than they paid.
     *
     * **ONLY THE GATEWAY LEG IS SCALED.** The recorded refund, the seller-ledger
     * reversal and the restock all stay on the full goods value: the seller was
     * paid in full and the platform funded the discount (ADR-084), so nothing about
     * what the merchant owes back changes because the buyer used points.
     *
     * **FLOORED, WHICH ROUNDS TOWARDS THE PLATFORM.** A half-kuruş cannot be sent
     * to a card; keeping it means the platform absorbs a rounding remainder it
     * already chose to absorb the discount for, rather than over-refunding.
     */
    private function cardShareOf(Payment $payment, int $basketAmountMinor): int
    {
        $settled = $payment->amount_minor + $payment->discount_minor;

        if ($settled <= 0 || $payment->amount_minor <= 0) {
            return 0;
        }

        return (int) floor($basketAmountMinor * $payment->amount_minor / $settled);
    }

    private function reverseAtGateway(Payment $payment, int $amountMinor): void
    {
        /*
        | **WHAT THE CARD IS OWED, NOT WHAT THE GOODS COST.** On a basket part-paid
        | with points the two differ, and PayTR is only ever asked for the card's
        | share — the rest comes back as points through `LoyaltyContract::reverse()`.
        */
        $cardMinor = $this->cardShareOf($payment, $amountMinor);

        if ($cardMinor <= 0) {
            /*
            | NOTHING TO ASK FOR, SO NOTHING IS ASKED — either the basket was paid
            | entirely with points, or this slice of it rounds to no card money at
            | all. The points come back through `LoyaltyContract::reverse()` on the
            | same path as any other refund; only the card leg is absent.
            */
            return;
        }

        $result = $this->gateway->refund(new PaymentRefundDTO(
            // `merchant_oid` IS the payment uuid — the same reference the charge
            // was made under (§4). PayTR knows no other name for it.
            reference: $payment->merchantReference(),
            amountMinor: $cardMinor,
        ));

        if (! $result->successful) {
            throw PaymentException::gatewayRejected($result->failureReason ?? 'unknown');
        }

        $this->providerReference = $result->providerReference;
    }

    /**
     * @param array{order_uuid: string, seller_org_uuid: string, amount_minor: int, commission_minor: int} $target
     */
    private function record(Payment $payment, array $target, RefundRequestDTO $request): void
    {
        PaymentRefund::query()->create([
            'payment_id' => $payment->getKey(),
            'payment_uuid' => $payment->uuid,
            'order_uuid' => $target['order_uuid'],
            'seller_org_uuid' => $target['seller_org_uuid'],
            'amount_minor' => $target['amount_minor'],
            'currency_id' => $payment->currency_id,
            'provider_reference' => $this->providerReference,
            'reason' => $request->reason,
            'created_by' => $request->actorId,
        ]);
    }

    /**
     * Take back the sale, and give back the commission (ADR-062, Payment.md §8).
     *
     * TWO ENTRIES, MIRRORING THE TWO THE SALE MADE. The debit removes the credit;
     * the credit removes the debit. Anything less than both means somebody keeps
     * money the sale no longer justifies — and if the platform kept its cut, the
     * seller would be paying for the buyer's return.
     *
     * THE BALANCE MAY GO NEGATIVE, and that is allowed by design (Payment.md §8):
     * if the seller was already paid out, the refund simply drives the sum below
     * zero and `CreatePayoutAction`'s ceiling blocks the next payout until later
     * sales make it whole. A balance that is a SUM is what makes that safe — a
     * mutable column would have had to choose between clamping at zero and losing
     * the money.
     *
     * @param array{order_uuid: string, seller_org_uuid: string, amount_minor: int, commission_minor: int} $target
     */
    private function reverseLedger(Payment $payment, array $target): void
    {
        $this->append($target['seller_org_uuid'], LedgerEntryType::RefundDebit, $target['amount_minor'], $payment->uuid, $target['order_uuid']);

        if ($target['commission_minor'] > 0) {
            $this->append($target['seller_org_uuid'], LedgerEntryType::RefundCommissionCredit, $target['commission_minor'], $payment->uuid, $target['order_uuid']);
        }
    }

    /**
     * `$magnitudeMinor` is always positive — `LedgerEntryType` owns the sign, so
     * no call site can append a positive refund debit and pay a seller for a
     * return.
     */
    private function append(
        string $sellerOrgUuid,
        LedgerEntryType $type,
        int $magnitudeMinor,
        string $paymentUuid,
        string $orderUuid,
    ): void {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $sellerOrgUuid,
            'type' => $type,
            'amount_minor' => $type->signedAmount($magnitudeMinor),
            'order_uuid' => $orderUuid,
            'payment_uuid' => $paymentUuid,
        ]);
    }

    /**
     * Put the units back (Payment.md §8, the mirror of §5's commit).
     *
     * KEYED ON THE SAME REFERENCES THE COMMIT USED — Order's own
     * `{order_uuid}:{variant_uuid}` strings, handed over by the read port so the
     * format stays in the module that invented it (ADR-049 amendment).
     *
     * ON-HAND ONLY: the hold ended when the sale completed and does not come back.
     *
     * @see `RestockAction` for why that asymmetry is correct.
     */
    private function restock(Payment $payment, string $orderUuid): void
    {
        foreach ($this->orders->reservationReferencesFor($orderUuid) as $reference) {
            try {
                $this->reservations->restock($reference);
            } catch (Throwable $exception) {
                /*
                | A FAILED RESTOCK MUST NOT UNDO A REFUND. The money has already
                | left the platform's account; refusing the whole operation would
                | leave the buyer refunded at the PSP and not refunded here, which
                | is the one state nothing later could reconcile. Logged for a
                | human, and Inventory's movement trail shows the gap.
                */
                Log::channel('errors')->error('Could not restock a reservation on refund', [
                    'payment_uuid' => $payment->uuid,
                    'order_uuid' => $orderUuid,
                    'reference' => $reference,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * How much of the customer's basket this refund undid, as a fraction.
     *
     * **THE DENOMINATOR IS CARD + POINTS, NOT THE CARD ALONE.** A 100 TL basket
     * settled with 60 TL of card and 40 TL of points, refunded in full, must return
     * all the points — dividing by the card charge would return 100/60 of them, and
     * clamping that to 1.0 would only hide the arithmetic being wrong.
     *
     * A fully-refunded payment is 1.0 outright rather than a division, so rounding
     * can never leave a customer a point short of whole.
     */
    private function refundedFraction(Payment $payment, int $refundedMinor, bool $fully): float
    {
        if ($fully) {
            return 1.0;
        }

        $settled = $payment->amount_minor + $payment->discount_minor;

        return $settled <= 0 ? 0.0 : $refundedMinor / $settled;
    }
}
