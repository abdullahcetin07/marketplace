<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listeners;

use App\Core\Domain\Contracts\CommissionQueryContract;
use App\Modules\Order\Application\Actions\RestoreCartFromUnpaidCheckoutAction;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Somebody else's event moved this module's state machine — money arriving
 * (Payment.md §3, §5, §6) and, since S2, a parcel arriving (Shipping.md §4).
 *
 * THE BOUNDARY MADE VISIBLE. Payment commits the stock itself — it is the caller
 * ADR-057 named — but it does not set an order's status, because a module that
 * reached into another's state machine would be the boundary failing at exactly
 * the point where it is most tempting to cut a corner. Payment says what
 * happened; this decides what that means for an order.
 *
 * SUBSCRIBED BY CLASS-STRING, so Order imports nothing from Payment — the same
 * name-is-not-an-import coupling Offer uses for `OrderCancelledBySeller` and
 * Inventory for Offer's stock events. The handler therefore takes an untyped
 * `object` and reads public properties off it: a plain object with the right
 * shape is all it can rely on.
 *
 * ITS COST, stated as the others state theirs: a rename in Payment breaks this at
 * RUNTIME rather than at build time. Bounded the same way — a feature test that
 * fires the real Payment callback and asserts these orders moved.
 *
 * IT IS IDEMPOTENT, because the callback behind it is. PayTR retries until it
 * hears "OK", so this may run more than once for one payment; an order already
 * `Paid` is skipped rather than re-transitioned.
 *
 * ONE ORDER'S FAILURE DOES NOT STOP THE REST. The group is N sellers' orders and
 * they are independent; a status that will not move is logged and the loop
 * continues, because leaving four orders unconfirmed because the fifth is odd is
 * worse than the odd one.
 *
 * @see docs/modules/Payment.md §3
 */
final class SettleOrdersOnPayment
{
    public function __construct(
        private readonly RestoreCartFromUnpaidCheckoutAction $restoreCart,
    ) {}

    /**
     * `App\Modules\Payment\Domain\Events\PaymentSucceeded` — untyped on purpose.
     */
    public function onSucceeded(object $event): void
    {
        /** @var array<int, string> $orderUuids */
        $orderUuids = $event->orderUuids ?? [];

        foreach (Order::query()->with('lines')->whereIn('uuid', $orderUuids)->get() as $order) {
            $this->freezeCommission($order);
            $this->transition($order, OrderStatus::Paid, (string) ($event->paymentUuid ?? ''));
        }
    }

    /**
     * `App\Modules\Payment\Domain\Events\PaymentFailed` — untyped on purpose.
     *
     * **IT GIVES THE BASKET BACK** (owner's decision, 2026-09-22, Order.md §13).
     *
     * THIS METHOD USED TO DO NOTHING BUT LOG, on the reasoning that a declined
     * shopper "may fix it and try again in thirty seconds" and that the orders
     * should therefore survive for them to pay again. The reasoning was sound and
     * the product was never built: no surface ever offered a second attempt at an
     * existing order, the cart had been emptied by checkout, and the failure page
     * sent the shopper to `/sepet` promising "Sepetiniz duruyor" — an empty one.
     * On production, 16 customers met that dead end and 3 of them ever bought
     * anything afterwards.
     *
     * So the recovery path is now the CART, which is where the storefront was
     * pointing all along: the lines go back, the orders expire, and the shopper
     * is exactly where they were before checkout. `RestoreCartFromUnpaidCheckoutAction`
     * holds the rules — what is skipped, and why running twice is safe.
     *
     * **IT ALSO CATCHES A LATE PAYMENT THAT WAS REFUNDED (ADR-072)**, and there
     * the restore is a deliberate no-op: those orders are already `Expired`, so
     * the action finds nothing in `AwaitingPayment` and returns zero. The stock
     * was gone, the money went back, and re-filling a basket the shopper may have
     * moved on from is not this event's business.
     *
     * **A FAILURE HERE MUST NOT FAIL THE CALLBACK.** This runs inside PayTR's
     * request, next to the listeners that open shipments and credit ledgers; an
     * exception escaping would cost the shopper far more than their basket.
     */
    public function onFailed(object $event): void
    {
        $checkoutGroupUuid = (string) ($event->checkoutGroupUuid ?? '');

        $restored = 0;

        try {
            if ($checkoutGroupUuid !== '') {
                $restored = $this->restoreCart->run($checkoutGroupUuid);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        Log::channel('errors')->info('A payment failed; the basket was handed back', [
            'payment_uuid' => $event->paymentUuid ?? null,
            'checkout_group_uuid' => $checkoutGroupUuid,
            'reason' => $event->reason ?? null,
            'lines_restored' => $restored,
        ]);
    }

    /**
     * `App\Modules\Payment\Domain\Events\PaymentRefunded` — untyped on purpose
     * (Payment.md §8, P5).
     *
     * IT MOVES ONLY THE ORDERS THE EVENT NAMES. A refund on this platform is per
     * seller's order, so the payload carries the list — marking the whole group
     * refunded because one parcel came back would cancel four sellers' sales for
     * a return that had nothing to do with them.
     *
     * THE STOCK IS ALREADY BACK by the time this runs. Payment restocks inside
     * the transaction and dispatches after commit — this is only the status, the
     * same division of labour as `onSucceeded`.
     *
     * IT DOES NOT TOUCH THE COMMISSION SNAPSHOT. The frozen figure is what the
     * platform DID take, and the refund's own ledger entry is what gave it back;
     * blanking the snapshot would erase the number the reversal was computed
     * from.
     */
    public function onRefunded(object $event): void
    {
        /** @var array<int, string> $orderUuids */
        $orderUuids = $event->orderUuids ?? [];

        /*
        | **THE SAME MONEY, TWO DIFFERENT ORDERS (ADR-065).** A refund is a refund
        | on the ledger whichever end of the lifecycle it happened at, so the
        | event's `cause` is the only thing that can say what it MEANS here. Goods
        | that reached the buyer and came back leave a `refunded` order; goods
        | that never left the seller leave a `cancelled` one — and a list showing
        | "iade edildi" for a parcel nobody ever packed is a support ticket.
        |
        | ONE EVENT WITH A CAUSE, not two events. Two would put two listeners in
        | this class racing to set different terminal states on one order, decided
        | by registration order.
        */
        $target = ($event->cause ?? 'return') === 'cancellation'
            ? OrderStatus::Cancelled
            : OrderStatus::Refunded;

        $reason = $event->reason ?? null;

        foreach (Order::query()->whereIn('uuid', $orderUuids)->get() as $order) {
            $this->transition($order, $target, (string) ($event->paymentUuid ?? ''), is_string($reason) ? $reason : null);
        }
    }

    /**
     * `App\Modules\Shipping\Domain\Events\ShipmentDelivered` — untyped on
     * purpose (Shipping.md §4, S2).
     *
     * THE SECOND MODULE THIS LISTENER SERVES, and the class name has stopped
     * being strictly accurate — it settles orders on a PAYMENT and now also on a
     * DELIVERY. Kept together anyway: both are "somebody else's event moved this
     * module's state machine", they share `transition()`, and a second listener
     * would mean two places to look for why an order's status changed.
     *
     * ONE SHIPMENT, ONE ORDER (ADR-063), so this takes a single order uuid where
     * the payment handler takes a list. A checkout group becomes N parcels and
     * each arrives on its own day.
     *
     * IT DOES NOT ASK WHY. Whether the buyer confirmed or the transit window
     * elapsed is on the event as `deliveredVia`, and it changes nothing here — an
     * order that arrived is delivered however the platform learned it. Payment's
     * S3 listener is where that provenance may matter.
     */
    public function onDelivered(object $event): void
    {
        $order = Order::query()->where('uuid', (string) ($event->orderUuid ?? ''))->first();

        if ($order === null) {
            Log::channel('errors')->warning('A delivered shipment named an order this module does not have', [
                'order_uuid' => $event->orderUuid ?? null,
                'shipment_uuid' => $event->shipmentUuid ?? null,
            ]);

            return;
        }

        $this->transition($order, OrderStatus::Delivered, (string) ($event->shipmentUuid ?? ''));
    }

    /**
     * Freeze what the platform takes on each line (ADR-061, Payment.md §6).
     *
     * AT PAYMENT, NOT AT CHECKOUT, and the timing is the decision. A rate edited
     * between placing and paying SHOULD apply — no money had changed hands — and
     * one edited afterwards must not. So the classification was frozen at checkout
     * (what the rules match against) and the commission is frozen here (what they
     * came to).
     *
     * ORDER WRITES IT, PAYMENT COMPUTES IT. `order_lines` is this module's
     * aggregate, and Payment reaching into it would be the boundary failing at the
     * same tempting point as setting an order's status. So the rate arrives
     * through the Core `CommissionQueryContract` and this method does the writing.
     *
     * IT READS THE SNAPSHOT, NEVER THE CATALOGUE. Brand, category and ancestry all
     * come off the line, so a product re-categorised next month cannot move a
     * commission on a sale already made.
     *
     * IDEMPOTENT, because the callback behind it is: a line whose commission is
     * already resolved is skipped, and `OrderLine`'s own guard refuses the write
     * even if this method forgot to.
     */
    private function freezeCommission(Order $order): void
    {
        $commissions = app(CommissionQueryContract::class);

        foreach ($order->lines as $line) {
            if ($line->commission_resolved_at !== null) {
                // A retried callback. The figure is final — see
                // `OrderLine::isSettlingCommission()`.
                continue;
            }

            $commission = $commissions->forLine(
                sellerOrgUuid: $order->selling_org_uuid,
                // KDV-INCLUSIVE (owner choice, Payment.md §6): the gross the buyer
                // paid for this line, not the net of tax.
                baseMinor: $line->line_total_minor,
                productUuid: $line->product_uuid,
                brandUuid: $line->brand_uuid,
                categoryPathUuids: $line->category_path_uuids ?? [],
            );

            $line->update([
                'commission_rate' => $commission['rate'],
                'commission_minor' => $commission['amount_minor'],
                'commission_resolved_at' => now(),
            ]);
        }
    }

    /**
     * @param string $reference the payment or shipment that caused the move,
     *                          for the log when a status will not budge
     */
    private function transition(Order $order, OrderStatus $target, string $reference, ?string $reason = null): void
    {
        if ($order->status === $target) {
            // A retried callback. Not an error — the correct response is silence.
            return;
        }

        if (! $order->status->canTransitionTo($target)) {
            Log::channel('errors')->warning('An order was not in a state that could be settled', [
                'order_uuid' => $order->uuid,
                'status' => $order->status->value,
                'target' => $target->value,
                'reference' => $reference,
            ]);

            return;
        }

        $attributes = ['status' => $target];

        if ($target === OrderStatus::Cancelled) {
            /*
            | STAMPED HERE BECAUSE ONLY HERE KNOWS IT HAPPENED (ADR-065). A
            | cancellation reached through a refund never touches
            | `CancelOrderAction`, so nothing else would fill the two columns the
            | order screen shows the buyer — and an empty "iptal" panel is worse
            | than no panel.
            */
            $attributes['cancelled_at'] = now();
            $attributes['cancellation_reason'] = $reason;
        }

        $order->forceFill($attributes)->save();

        if ($target === OrderStatus::Cancelled) {
            $this->closeOpenCancellationRequest($order);
        }
    }

    /**
     * A buyer's cancellation request outlives its own answer, unless something
     * closes it (2026-09-22, ADR-065 C2).
     *
     * **THE ORDER CAN BE CANCELLED WITHOUT ANYBODY ANSWERING THE REQUEST.** The
     * seller presses "gönderemiyorum" on the line, the refund goes through, the
     * order ends `cancelled` — and the buyer's pending request sat in the
     * seller's queue asking for something that had already happened. On
     * production, SP-260919-BCRMA9 did exactly that.
     *
     * **`Approved` IS THE HONEST ANSWER even though no seller pressed it**: the
     * buyer asked for the order to be cancelled and the order is cancelled. The
     * distinction lives in `decided_by`, which stays NULL precisely because no
     * person decided — ADR-065's own rule that an approved request is not where
     * the cancellation lives, only a record of the asking and the answering.
     *
     * Only `pending` rows are touched, so a request a seller genuinely rejected
     * keeps their answer.
     */
    private function closeOpenCancellationRequest(Order $order): void
    {
        CancellationRequest::query()
            ->where('order_uuid', $order->uuid)
            ->where('status', CancellationRequestStatus::Pending->value)
            ->update([
                'status' => CancellationRequestStatus::Approved->value,
                'decision_reason' => __('order.cancellation.settled_by_cancellation'),
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
