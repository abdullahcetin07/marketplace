<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Presentation\Controllers\Api;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Shipping\Application\Actions\ConfirmReceiptAction;
use App\Modules\Shipping\Domain\Exceptions\ShippingException;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Shipping\Presentation\Resources\ShipmentResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;

/**
 * The buyer's two shipment endpoints (Shipping.md §6).
 *
 * BOTH ARE KEYED ON THE ORDER, not on the shipment. The customer already holds
 * their order uuid; making them fetch a shipment uuid first would put a second
 * identifier on a public surface for no benefit — one more thing to scope, and
 * one more thing to probe.
 *
 * `show` IS WHAT "SİPARİŞLERİM" READS — status, carrier, tracking number and the
 * link built from the carrier's own template. `confirm` is the "Teslim aldım"
 * button beside it.
 *
 * CUSTOMER-SCOPED THROUGH ORDER, because a shipment carries who has to SEND the
 * parcel, not who receives it — and Shipping imports no module, so the Core port
 * answers who bought it.
 *
 * NOTHING HERE CAN SET A DELIVERY DATE EXCEPT THE BUYER'S OWN CONFIRMATION. There
 * is no seller or admin route in this module that writes `delivered_at` (ADR-064).
 *
 * @see docs/modules/Shipping.md §3, §6
 */
final class ShipmentController extends BaseController
{
    public function __construct(
        private readonly ConfirmReceiptAction $confirm,
        private readonly OrderQueryContract $orders,
    ) {}

    /**
     * GET /api/v1/orders/{order}/shipment
     */
    public function show(string $order): JsonResponse
    {
        return $this->ok(new ShipmentResource($this->resolve($order)));
    }

    /**
     * POST /api/v1/orders/{order}/shipment/confirm — "Teslim aldım".
     */
    public function confirm(string $order): JsonResponse
    {
        $actor = current_actor();

        if ($actor === null) {
            throw ShippingException::notFound($order);
        }

        $shipment = $this->confirm->run($order, (int) $actor->getKey());

        return $this->ok(new ShipmentResource($shipment->fresh()->load('cargoCompany')));
    }

    /**
     * The signed-in customer's own parcel for this order.
     *
     * ONE ANSWER FOR "no such order", "not yours" AND "not shipped yet" — the rule
     * every public surface here keeps. Telling them apart would let anyone
     * discover whether an order uuid is real by watching which error comes back.
     */
    private function resolve(string $orderUuid): Shipment
    {
        $actor = current_actor();

        /*
        | BY SHAPE FIRST (ADR-059). `shipments.order_uuid` is a native uuid column
        | on PostgreSQL, so a non-uuid segment would be SQLSTATE[22P02] — a 500 on
        | a page the customer opens — while SQLite quietly returns nothing. The
        | trap, ninth watch.
        */
        if ($actor === null || ! PublicKey::looksLikeUuid($orderUuid)) {
            throw ShippingException::notFound($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['customer_id'] !== (int) $actor->getKey()) {
            throw ShippingException::notFound($orderUuid);
        }

        $shipment = Shipment::query()->with('cargoCompany')->where('order_uuid', $orderUuid)->first();

        if ($shipment === null) {
            throw ShippingException::notFound($orderUuid);
        }

        return $shipment;
    }
}
