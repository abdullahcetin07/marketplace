<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Order\Domain\Enums\CancellationRequestStatus;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CancellationRequest;
use App\Modules\Order\Domain\Models\Order;

/**
 * "İptal edebilir miyiz?" — the buyer asks (ADR-065, C2).
 *
 * **THE BUYER DOES NOT CANCEL. THEY ASK.** That is ADR-065's ruling and it is the
 * whole shape of this action: a paid order may already be picked and packed, and
 * yanking it out from under the person doing that work is not a decision the
 * other party gets to make alone. So this writes a `pending` row and nothing
 * else — no money moves, no stock moves, and the order does not change state.
 *
 * THREE THINGS ARE CHECKED HERE:
 *
 *   PAID       an unpaid order needs none of this. The customer's own
 *              `CancelOrderAction` walks away from it outright, because nothing
 *              has been collected and nothing committed — @see
 *              `OrderStatus::isCancellableWithoutRefund()`.
 *   UNSHIPPED  the parcel is still awaiting handover, read through the Core
 *              Shipping port. **The same gate the seller's own cancel uses**,
 *              asked the same way, because two spellings of one rule is how the
 *              buyer's door and the seller's door start disagreeing about which
 *              orders are still cancellable.
 *   ONE OPEN   there is no request already waiting for an answer. A rejected one
 *              does NOT block asking again: circumstances change, and a seller
 *              who said no on Monday may say yes on Thursday.
 *
 * **IT IS NOT IDEMPOTENT, AND THAT IS DELIBERATE.** A second tap while one is
 * open is a REFUSAL rather than a silent no-op returning the existing row,
 * because the two are indistinguishable to the buyer and the refusal is the
 * truthful one: their request is already in front of the seller. Silence would
 * read as "sent again", and a buyer who thinks they have nudged somebody has been
 * misled.
 *
 * @see docs/modules/Order.md §3.3
 */
final class RequestOrderCancellationAction extends BaseAction
{
    public function __construct(private readonly ShipmentQueryContract $shipments) {}

    public function handle(mixed ...$arguments): CancellationRequest
    {
        /** @var Order $order */
        $order = $arguments[0];
        $customerId = (int) $arguments[1];
        $reason = $arguments[2] ?? null;

        if ($order->status !== OrderStatus::Paid) {
            throw OrderException::notCancellableByRequest($order->uuid);
        }

        if (! $this->shipments->isAwaitingHandover($order->uuid)) {
            /*
            | THE ADR-065 GATE. Once the box is with a carrier the seller has
            | spent the effort and the buyer's route is the RETURN (ADR-064) —
            | a different operation, with a different window and a parcel that
            | has to physically come back.
            |
            | A MISSING SHIPMENT REFUSES, exactly as it does on the seller's side:
            | an order with no row cannot prove it is still on a shelf, and asking
            | a seller to approve a cancellation for a parcel that may already be
            | moving is the mistake nothing later undoes.
            */
            throw OrderException::notCancellableByRequest($order->uuid);
        }

        if (CancellationRequest::query()->forOrder($order->uuid)->open()->exists()) {
            throw OrderException::cancellationAlreadyRequested($order->uuid);
        }

        return CancellationRequest::query()->create([
            'order_uuid' => $order->uuid,
            'requested_by' => $customerId,
            'reason' => $reason,
            'status' => CancellationRequestStatus::Pending,
        ]);
    }
}
