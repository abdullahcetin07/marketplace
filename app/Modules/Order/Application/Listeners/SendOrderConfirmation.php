<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listeners;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Models\Customer;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Infrastructure\Notifications\OrderConfirmationNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The receipt the buyer never used to get (Order.md §14).
 *
 * **IT HANGS OFF `PaymentSucceeded`, NOT PLACEMENT.** An order in
 * `AwaitingPayment` is an intention — it expires on its own five minutes later
 * (ADR-072) — and confirming a purchase that never happened is worse than
 * confirming nothing. Money arriving is the moment there is something to
 * confirm, and it is the moment the customer expects the e-mail.
 *
 * **BY CLASS-STRING**, like every cross-module subscription here: Order imports
 * no module and reads the payload's public properties off a plain object.
 *
 * **ONE E-MAIL FOR THE WHOLE GROUP.** The notification explains why; this only
 * assembles it — every seller order the payment covered, in the order they were
 * created, each with the store's own name.
 *
 * **IT CAN NEVER COST A PAYMENT.** This runs inside PayTR's callback beside the
 * listeners that confirm orders, open shipments and credit ledgers. A missing
 * customer row, an unreachable store name, a mail server refusing the queue —
 * none of them may turn a successful charge into a failed callback, so
 * everything is caught and reported. A receipt is worth less than the sale.
 */
final class SendOrderConfirmation
{
    public function __construct(
        private readonly StoreQueryContract $stores,
    ) {}

    public function handle(object $event): void
    {
        try {
            $this->send($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function send(object $event): void
    {
        /** @var array<int, string> $orderUuids */
        $orderUuids = array_values((array) ($event->orderUuids ?? []));

        if ($orderUuids === []) {
            return;
        }

        /** @var array<int, Order> $orders */
        $orders = Order::query()
            /*
            | `currency` IS EAGER-LOADED OR STRICT MODE THROWS — and only on a
            | multi-seller basket, because Laravel arms the lazy-loading guard at
            | count > 1 (CLAUDE.md). A single-order test would have proved
            | nothing; the two-seller one caught it.
            */
            ->with(['lines', 'currency'])
            ->whereIn('uuid', $orderUuids)
            ->orderBy('id')
            ->get()
            ->all();

        if ($orders === []) {
            return;
        }

        $customer = Customer::query()->where('uuid', $orders[0]->customer_uuid)->first();

        if (! $customer instanceof Customer || ! is_string($customer->email) || $customer->email === '') {
            // A deleted account, or one with no address on it. The charge stands
            // either way; there is simply nobody to write to.
            Log::channel('errors')->info('A paid order had no customer to confirm to', [
                'checkout_group_uuid' => $event->checkoutGroupUuid ?? null,
            ]);

            return;
        }

        // One lookup for every seller in the basket, not one per order.
        $profiles = $this->stores->publicProfilesFor(
            array_values(array_unique(array_map(
                static fn (Order $order): string => (string) $order->store_uuid,
                $orders,
            ))),
        );

        $blocks = [];
        $grandTotalMinor = 0;

        foreach ($orders as $order) {
            $currency = $order->currency;
            $grandTotalMinor += (int) $order->grand_total_minor;

            $lines = [];

            foreach ($order->lines as $line) {
                $lines[] = [
                    'title' => (string) $line->product_title,
                    'quantity' => (int) $line->quantity,
                    'total' => money((int) $line->line_total_minor, $currency),
                ];
            }

            $blocks[] = [
                'number' => (string) $order->order_number,
                // The shop's own name, never the legal entity's: it is what the
                // buyer chose and what the parcel will say.
                'seller' => $profiles[(string) $order->store_uuid]['name']
                    ?? __('order.confirmation.unknown_seller'),
                'total' => money((int) $order->grand_total_minor, $currency),
                'lines' => $lines,
            ];
        }

        $customer->notify(new OrderConfirmationNotification(
            orders: $blocks,
            grandTotal: money($grandTotalMinor, $orders[0]->currency),
            shippingAddress: $this->address($orders[0]),
            ordersUrl: rtrim((string) config('marketplace.frontend_url'), '/').'/hesap/siparislerim',
        ));
    }

    /**
     * The frozen shipping address, in one line.
     *
     * Read off the ORDER rather than the address book: the buyer may have edited
     * or deleted that entry since, and the parcel is going where they said at
     * checkout (ADR-056).
     */
    private function address(Order $order): string
    {
        /** @var array<string, string|null> $snapshot */
        $snapshot = (array) $order->shipping_address;

        $parts = array_filter([
            $snapshot['recipient_name'] ?? null,
            $snapshot['line1'] ?? null,
            $snapshot['district'] ?? null,
            $snapshot['city'] ?? null,
        ], static fn (?string $part): bool => is_string($part) && $part !== '');

        return implode(', ', $parts);
    }
}
