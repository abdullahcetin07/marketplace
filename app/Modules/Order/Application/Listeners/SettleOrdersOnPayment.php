<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listeners;

use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Payment collected the money; Order moves its own orders (Payment.md §3, §5).
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

        foreach (Order::query()->whereIn('uuid', $orderUuids)->get() as $order) {
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

    private function transition(Order $order, OrderStatus $target, string $paymentUuid): void
    {
        if ($order->status === $target) {
            // A retried callback. Not an error — the correct response is silence.
            return;
        }

        if (! $order->status->canTransitionTo($target)) {
            Log::channel('errors')->warning('A paid order was not in a state that could be settled', [
                'order_uuid' => $order->uuid,
                'status' => $order->status->value,
                'payment_uuid' => $paymentUuid,
            ]);

            return;
        }

        $order->forceFill(['status' => $target])->save();
    }
}
