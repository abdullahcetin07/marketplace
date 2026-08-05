<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Shipping\Application\Actions\Concerns\RecordsDelivery;
use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Events\ShipmentDelivered;
use App\Modules\Shipping\Domain\Exceptions\ShippingException;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Shared\Support\PublicKey;

/**
 * "Teslim aldım" — the buyer says the parcel arrived (ADR-064, Shipping.md §3).
 *
 * **THE MOST TRUSTWORTHY DELIVERY SIGNAL THE PLATFORM HAS**, and the only one a
 * human gives it. The person who physically received the box said so; everything
 * else in this module is a heuristic standing in for this.
 *
 * IT IS ALSO A FAVOUR TO THE BUYER, not just to the seller. Confirming early
 * starts their OWN return clock (S3) — a buyer who has the goods and knows they
 * want to send them back does not benefit from the platform pretending the parcel
 * is still in transit for another two days.
 *
 * KEYED ON THE ORDER, NOT THE SHIPMENT, and that is a deliberate API choice. The
 * customer already holds their order uuid; making them fetch a shipment uuid first
 * would mean a read endpoint they need for nothing else, and every extra
 * identifier on a public surface is another thing to scope and probe.
 *
 * OWNERSHIP IS ASKED OF ORDER, because Shipping imports no module and a shipment
 * does not carry the customer — it carries who has to SEND the parcel, not who
 * receives it. The Core port answers who bought it.
 *
 * A SECOND TAP IS A NO-OP, NOT A REFUSAL — the opposite of "kargoya ver", and for
 * the opposite reason. There is nothing to lose: the parcel is already delivered
 * and the buyer is telling us something we know. Re-stamping the date, on the
 * other hand, would silently move a payout schedule and a return deadline, which
 * is why `recordDelivery()` refuses that rather than this.
 *
 * @see App\Modules\Shipping\Application\Actions\Concerns\RecordsDelivery
 * @see docs/modules/Shipping.md §3
 */
final class ConfirmReceiptAction extends BaseAction
{
    use RecordsDelivery;

    private ?ShipmentDelivered $delivered = null;

    public function __construct(private readonly OrderQueryContract $orders) {}

    /**
     * `run($orderUuid, $customerId)` — the buyer's own order, and the acting
     * customer's key for the ownership check.
     */
    public function handle(mixed ...$arguments): Shipment
    {
        $orderUuid = (string) $arguments[0];
        $customerId = (int) $arguments[1];

        $shipment = $this->resolve($orderUuid, $customerId);

        $this->delivered = $this->recordDelivery($shipment, DeliveredVia::Buyer);

        return $shipment;
    }

    /**
     * Dispatched AFTER COMMIT, so no listener schedules a payout or opens a return
     * window against a transaction that then rolls back.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->delivered !== null) {
            event($this->delivered);
        }
    }

    /**
     * The buyer's own parcel, or a refusal that says nothing about which.
     *
     * ONE ANSWER FOR "no such order", "not yours" AND "no parcel yet" — the rule
     * every public surface here keeps. Telling them apart would let anyone
     * discover whether an order uuid is real, and whether it has shipped, by
     * watching which error comes back.
     */
    private function resolve(string $orderUuid, int $customerId): Shipment
    {
        /*
        | BY SHAPE FIRST (ADR-059). `shipments.order_uuid` is a native uuid column
        | on PostgreSQL, so `where('order_uuid', 'siparisim')` is SQLSTATE[22P02]
        | — a 500 on a button the customer taps — while SQLite quietly returns
        | nothing. The trap, ninth watch.
        */
        if (! PublicKey::looksLikeUuid($orderUuid)) {
            throw ShippingException::notFound($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['customer_id'] !== $customerId) {
            throw ShippingException::notFound($orderUuid);
        }

        $shipment = Shipment::query()->where('order_uuid', $orderUuid)->first();

        if ($shipment === null) {
            throw ShippingException::notFound($orderUuid);
        }

        return $shipment;
    }
}
