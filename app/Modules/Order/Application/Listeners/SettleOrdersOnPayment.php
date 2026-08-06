<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listeners;

use App\Core\Domain\Contracts\CommissionQueryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;

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
     * IT DOES NOT CANCEL THE ORDERS, and that is a decision rather than an
     * omission. A declined card is a shopper who may fix it and try again in
     * thirty seconds; cancelling would throw away the basket they assembled and,
     * worse, is irreversible (`Cancelled` is terminal in both directions). The
     * stock has already gone back — Payment released it inside the callback — so
     * nothing is being hoarded meanwhile, and the existing 30-minute expiry sweep
     * cancels what is genuinely abandoned.
     *
     * So this LOGS and leaves the orders where they are. The method exists so
     * that decision is written down somewhere a future reader will find it,
     * rather than being invisible in the absence of a listener.
     */
    public function onFailed(object $event): void
    {
        Log::channel('errors')->info('A payment failed; its orders keep awaiting payment', [
            'payment_uuid' => $event->paymentUuid ?? null,
            'checkout_group_uuid' => $event->checkoutGroupUuid ?? null,
            'reason' => $event->reason ?? null,
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
    }
}
