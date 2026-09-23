<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listeners;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Models\Customer;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Infrastructure\Notifications\ShipmentShippedNotification;
use Throwable;

/**
 * Tells the buyer their parcel is on its way (Order.md §14).
 *
 * **ORDER SENDS IT, NOT SHIPPING**, and the reason is the customer: this module
 * owns the order and therefore the person who bought it, while Shipping knows a
 * parcel and a carrier. `ShipmentShipped` arrives BY CLASS-STRING with
 * everything the e-mail needs — including the carrier's tracking URL, which
 * Shipping resolves from its own table so nobody has to import it.
 *
 * **ONE E-MAIL PER PARCEL.** The confirmation covers a whole basket because the
 * shopper paid once; this does not, because each seller hands their parcel over
 * on their own day. A buyer waiting on two wants to hear about each as it
 * leaves.
 *
 * **IT CANNOT COST A HANDOVER.** The seller pressing "kargoya verdim" must
 * succeed whatever the mail layer is doing, so every failure here is reported
 * and swallowed — the same rule as the confirmation.
 */
final class SendShipmentNotification
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
        $orderUuid = (string) ($event->orderUuid ?? '');

        if ($orderUuid === '') {
            return;
        }

        $order = Order::query()->where('uuid', $orderUuid)->first();

        if (! $order instanceof Order) {
            return;
        }

        $customer = Customer::query()->where('uuid', $order->customer_uuid)->first();

        if (! $customer instanceof Customer || ! is_string($customer->email) || $customer->email === '') {
            return;
        }

        $profiles = $this->stores->publicProfilesFor([(string) $order->store_uuid]);

        $customer->notify(new ShipmentShippedNotification(
            orderNumber: (string) $order->order_number,
            seller: $profiles[(string) $order->store_uuid]['name'] ?? __('order.confirmation.unknown_seller'),
            carrier: (string) ($event->cargoCompanyName ?? ''),
            trackingNumber: (string) ($event->trackingNumber ?? ''),
            trackingUrl: is_string($event->trackingUrl ?? null) ? $event->trackingUrl : null,
            ordersUrl: rtrim((string) config('marketplace.frontend_url'), '/').'/hesap/siparislerim',
        ));
    }
}
