<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Listeners;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentCreated;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Money arrived, so somebody has to put things in boxes (ADR-063).
 *
 * SUBSCRIBED BY CLASS-STRING, so Shipping imports nothing from Payment — the same
 * name-is-not-an-import coupling Order uses for `PaymentSucceeded`, Offer for
 * `OrderCancelledBySeller` and Inventory for Offer's stock events. The handler
 * therefore takes an untyped `object` and reads public properties off it: a plain
 * object with the right shape is all it can rely on.
 *
 * ITS COST, stated as the others state theirs: a rename in Payment breaks this at
 * RUNTIME rather than at build time. Bounded the same way — a feature test that
 * fires the real Payment callback and asserts the shipments appeared.
 *
 * **IDEMPOTENT AT THE DATABASE, NOT IN THIS CLASS.** PayTR retries until it hears
 * "OK", so this runs many times for one payment — and the check-then-insert below
 * is a race, not a guarantee: two callbacks arriving together both see no
 * shipment. The UNIQUE index on `order_uuid` is what actually holds, and the
 * caught violation is the losing thread finding out it lost. Without it a seller
 * would be handed a second parcel to ship for one order.
 *
 * ONE ORDER'S FAILURE DOES NOT STOP THE REST. The group is N sellers' orders and
 * they are independent; an order that will not resolve is logged and the loop
 * continues, because leaving four sellers unable to ship because the fifth is odd
 * is worse than the odd one.
 *
 * IT ASKS ORDER WHO THE SELLER IS. The event carries order uuids and nothing else
 * — a parcel belongs to whoever has to pack it, and that lives in Order, behind
 * `OrderQueryContract`.
 *
 * @see docs/modules/Shipping.md §2
 */
final class CreateShipmentsOnPayment
{
    public function __construct(private readonly OrderQueryContract $orders) {}

    /**
     * `App\Modules\Payment\Domain\Events\PaymentSucceeded` — untyped on purpose.
     */
    public function handle(object $event): void
    {
        /** @var array<int, string> $orderUuids */
        $orderUuids = $event->orderUuids ?? [];

        foreach ($orderUuids as $orderUuid) {
            $this->createFor((string) $orderUuid, (string) ($event->paymentUuid ?? ''));
        }
    }

    private function createFor(string $orderUuid, string $paymentUuid): void
    {
        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null) {
            Log::channel('errors')->error('Cannot create a shipment for an order that does not resolve', [
                'payment_uuid' => $paymentUuid,
                'order_uuid' => $orderUuid,
            ]);

            return;
        }

        // The common retry path. Not an error — the correct response is silence.
        if (Shipment::query()->where('order_uuid', $orderUuid)->exists()) {
            return;
        }

        try {
            $shipment = Shipment::query()->create([
                'order_uuid' => $orderUuid,
                'seller_org_uuid' => $fulfilment['selling_org_uuid'],
                'order_number' => $fulfilment['order_number'],
                'status' => ShipmentStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two callbacks raced. The index did its job; the other one won.
            return;
        }

        ShipmentCreated::dispatch(
            $shipment->uuid,
            $shipment->order_uuid,
            $shipment->seller_org_uuid,
            $shipment->order_number,
        );
    }
}
