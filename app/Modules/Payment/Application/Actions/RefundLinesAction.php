<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Events\PaymentRefunded;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Support\RefundableLines;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send back part of an order: these lines, this many (S4).
 *
 * **P5 REFUNDED WHOLE ORDERS; THIS REFUNDS ITEMS.** The shape is the same and the
 * order of operations is unchanged — the PSP goes first, then the rows, then the
 * ledger, then the stock — because the reasons have not changed either: nothing
 * may be written until PayTR has actually agreed, and unlike a payment there is no
 * callback coming later to correct it.
 *
 * WHAT IS GENUINELY NEW IS THE ARITHMETIC AND THE GUARD.
 *
 *   PROPORTIONAL   the amount is the units' KDV-inclusive price and the commission
 *                  is the FROZEN figure scaled to the same share (ADR-061). @see
 *                  `RefundableLines` — including why the KDV needs no separate
 *                  term, and why the last unit of a line takes the remainder.
 *   REMAINING      a line may go back up to what has not already gone back. That
 *                  check replaced P5's unique index, which had to go because a
 *                  second refund of one order is now legitimate.
 *   PER QUANTITY   Inventory restocks the returned COUNT, not the whole hold —
 *                  the S4 amendment to the ADR-049 command port.
 *
 * **THE SHIPMENT IS MARKED RETURNED ONLY WHEN THE ORDER IS FULLY BACK**, by an
 * event Shipping consumes — this module does not touch a shipment any more than it
 * touches an order's status. A partly returned parcel is still a delivered parcel.
 *
 * IT IMPORTS NO MODULE. Lines arrive through `OrderQueryContract`, stock moves
 * through `InventoryReservationContract`, and Order and Shipping each move their
 * own state on `PaymentRefunded`.
 *
 * @see docs/modules/Payment.md §8
 */
final class RefundLinesAction extends BaseAction
{
    private ?PaymentRefunded $refunded = null;

    private ?string $providerReference = null;

    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly OrderQueryContract $orders,
        private readonly InventoryReservationContract $reservations,
    ) {}

    public function handle(mixed ...$arguments): PaymentRefund
    {
        /** @var ReturnRequestDTO $request */
        $request = $arguments[0];

        $payment = $this->payment($request->orderUuid);
        $priced = $this->price($request);

        if ($priced === []) {
            throw PaymentException::nothingToRefund($payment->uuid);
        }

        $amountMinor = array_sum(array_map(static fn (RefundableLines $l): int => $l->amountMinor, $priced));

        if ($amountMinor <= 0) {
            throw PaymentException::nothingToRefund($payment->uuid);
        }

        // THE PSP GOES FIRST. Nothing below is true unless it agrees, and there is
        // no callback coming later to correct it.
        $this->reverseAtGateway($payment, $amountMinor);

        $refund = $this->record($payment, $request, $priced, $amountMinor);

        $this->reverseLedger($payment, $refund, $priced);
        $this->restock($payment, $request->orderUuid, $priced);

        $this->settle($payment, $request->orderUuid, $amountMinor);

        return $refund;
    }

    /**
     * Dispatched AFTER COMMIT — no consumer may move an order or a shipment on the
     * strength of a transaction that then rolls back.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->refunded !== null) {
            event($this->refunded);
        }
    }

    /**
     * The payment behind this order.
     *
     * REFUSED UNLESS IT COLLECTED. A payment that never took money has nothing to
     * reverse, and asking the PSP about it would be asking about a transaction it
     * does not have.
     */
    private function payment(string $orderUuid): Payment
    {
        $payment = Payment::query()
            ->with('currency')
            ->where('checkout_group_uuid', $this->checkoutGroupOf($orderUuid))
            ->lockForUpdate()
            ->first();

        if ($payment === null || ! $payment->status->isSettled()) {
            throw PaymentException::notRefundable($orderUuid, $payment?->status->value ?? 'none');
        }

        return $payment;
    }

    /**
     * Which checkout group an order belongs to.
     *
     * ASKED OF ORDER, THROUGH THE PORT — and the first draft of this method did
     * not, which is worth recording. It walked every settled payment's group
     * looking for the order, on the argument that the containing group is
     * derivable from what the port already exposed. It is. It is also a scan of
     * every settled payment on the platform plus a query per payment, on an
     * endpoint a customer taps, so S4 added `checkoutGroupFor()` instead.
     *
     * NO SUCH ORDER AND NO GROUP ANSWER THE SAME WAY, like every other refusal on
     * this surface.
     */
    private function checkoutGroupOf(string $orderUuid): string
    {
        $group = $this->orders->checkoutGroupFor($orderUuid);

        if ($group === null || $group === '') {
            throw PaymentException::groupNotFound($orderUuid);
        }

        return $group;
    }

    /**
     * Price every line the caller named, dropping the ones that may not go back.
     *
     * A LINE THAT CANNOT BE REFUNDED IS ABSENT, NOT AN ERROR — unless nothing
     * survives, which is `nothingToRefund`. A buyer returning two items where one
     * was already sent back last week should get the other one refunded, not a
     * refusal they cannot act on.
     *
     * @return array<int, RefundableLines>
     */
    private function price(ReturnRequestDTO $request): array
    {
        $priced = [];

        foreach ($this->orders->orderLines($request->orderUuid) as $line) {
            $wanted = $request->quantities[$line['id']] ?? 0;

            if ($wanted <= 0) {
                continue;
            }

            $priceable = RefundableLines::price($line, (int) $wanted);

            if ($priceable !== null) {
                $priced[] = $priceable;
            }
        }

        return $priced;
    }

    private function reverseAtGateway(Payment $payment, int $amountMinor): void
    {
        $result = $this->gateway->refund(new PaymentRefundDTO(
            // PayTR knows the charge by ONE name — the payment uuid (§4). A
            // partial refund is the same reference and a smaller amount.
            reference: $payment->merchantReference(),
            amountMinor: $amountMinor,
        ));

        if (! $result->successful) {
            throw PaymentException::gatewayRejected($result->failureReason ?? 'unknown');
        }

        $this->providerReference = $result->providerReference;
    }

    /**
     * @param array<int, RefundableLines> $priced
     */
    private function record(Payment $payment, ReturnRequestDTO $request, array $priced, int $amountMinor): PaymentRefund
    {
        $fulfilment = $this->orders->orderFulfilment($request->orderUuid);

        $refund = PaymentRefund::query()->create([
            'payment_id' => $payment->getKey(),
            'payment_uuid' => $payment->uuid,
            'order_uuid' => $request->orderUuid,
            'seller_org_uuid' => $fulfilment['selling_org_uuid'] ?? '',
            'amount_minor' => $amountMinor,
            'currency_id' => $payment->currency_id,
            'provider_reference' => $this->providerReference,
            'reason' => $request->reason,
            'created_by' => $request->actorId,
        ]);

        foreach ($priced as $line) {
            PaymentRefundLine::query()->create([
                'payment_refund_id' => $refund->getKey(),
                'order_line_uuid' => $line->orderLineUuid,
                'variant_uuid' => $line->variantUuid,
                'quantity' => $line->quantity,
                'amount_minor' => $line->amountMinor,
                'commission_minor' => $line->commissionMinor,
            ]);
        }

        return $refund;
    }

    /**
     * Take back the sale and give back the commission — for this much of it.
     *
     * THE SAME TWO ENTRIES P5 MAKES, at a partial amount. Anything less than both
     * means the platform keeps its cut on goods it no longer sold, which is the
     * seller paying for the buyer's return.
     *
     * @param array<int, RefundableLines> $priced
     */
    private function reverseLedger(Payment $payment, PaymentRefund $refund, array $priced): void
    {
        $amount = array_sum(array_map(static fn (RefundableLines $l): int => $l->amountMinor, $priced));
        $commission = array_sum(array_map(static fn (RefundableLines $l): int => $l->commissionMinor, $priced));

        $this->append($refund->seller_org_uuid, LedgerEntryType::RefundDebit, (int) $amount, $payment->uuid, $refund->order_uuid);

        if ($commission > 0) {
            $this->append($refund->seller_org_uuid, LedgerEntryType::RefundCommissionCredit, (int) $commission, $payment->uuid, $refund->order_uuid);
        }
    }

    /**
     * `$magnitudeMinor` is always positive — `LedgerEntryType` owns the sign.
     *
     * THE UNIQUE INDEX ON `(payment, order, type)` HAD TO GO WITH P5'S, for the
     * same reason: a second partial refund of one order is legitimate and must be
     * able to append its own pair.
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
     * Put back exactly the units that came back.
     *
     * PER QUANTITY, which is the S4 amendment to the command port: P5 restocked a
     * whole reservation because a refund was whole-order.
     *
     * **THE REFERENCE IS LOOKED UP, NEVER BUILT HERE.** ADR-049's key is
     * `{order_uuid}:{variant_uuid}` and the format belongs to Order — this module
     * writing that colon is exactly the drift `reservationReferencesFor()` exists
     * to prevent, and the two would part company the day the scheme changed. S4
     * keyed that method by variant so a line-level refund can ask for one.
     *
     * A LINE WITH NO RESERVATION IS SKIPPED, not guessed at: an order placed
     * before reservations existed has no hold to give back.
     *
     * A FAILED RESTOCK DOES NOT UNDO A REFUND. The money has left; refusing the
     * whole operation would leave the buyer refunded at the PSP and not here,
     * which is the one state nothing later can reconcile.
     *
     * @param array<int, RefundableLines> $priced
     */
    private function restock(Payment $payment, string $orderUuid, array $priced): void
    {
        $references = $this->orders->reservationReferencesFor($orderUuid);

        foreach ($priced as $line) {
            $reference = $references[$line->variantUuid] ?? null;

            if ($reference === null) {
                continue;
            }

            try {
                $this->reservations->restock($reference, $line->quantity);
            } catch (Throwable $exception) {
                Log::channel('errors')->error('Could not restock a returned quantity', [
                    'payment_uuid' => $payment->uuid,
                    'order_uuid' => $orderUuid,
                    'reference' => $reference,
                    'quantity' => $line->quantity,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Move the payment, and announce what came back.
     *
     * `refunded` ONLY WHEN THE WHOLE BASKET IS BACK — Σ of the refund rows against
     * what was charged, the same rule P5 established. One returned shoe leaves a
     * multi-seller basket `partially_refunded`, which is what it is.
     *
     * THE EVENT CARRIES WHETHER THIS ORDER IS NOW FULLY RETURNED, because that is
     * the question Shipping asks to decide whether the parcel is `returned` and
     * Order asks to decide whether it is `refunded`. Neither should have to
     * recompute it from line quantities this module already summed.
     */
    private function settle(Payment $payment, string $orderUuid, int $amountMinor): void
    {
        $refundedTotal = PaymentRefund::refundedMinorFor((int) $payment->getKey());
        $fully = $refundedTotal >= $payment->amount_minor;

        $payment->forceFill([
            'status' => $fully ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            'refunded_at' => now(),
        ])->save();

        $this->refunded = new PaymentRefunded(
            paymentUuid: $payment->uuid,
            checkoutGroupUuid: $payment->checkout_group_uuid,
            amountMinor: $amountMinor,
            currencyCode: $payment->currency->code,
            orderUuids: $this->fullyReturned($orderUuid) ? [$orderUuid] : [],
            fullyRefunded: $fully,
        );
    }

    /**
     * Whether every unit of every line of this order has now gone back.
     *
     * **THE QUESTION THAT DECIDES WHETHER AN ORDER IS REFUNDED OR JUST PARTLY
     * SO.** A partly returned order is still a delivered order with goods the
     * buyer kept — moving it to `refunded` would tell every downstream screen the
     * sale is undone when most of it stands. So the event names the order only
     * when it is genuinely, wholly back.
     */
    private function fullyReturned(string $orderUuid): bool
    {
        $lines = $this->orders->orderLines($orderUuid);

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (PaymentRefundLine::refundedQuantityFor($line['id']) < $line['quantity']) {
                return false;
            }
        }

        return true;
    }
}
