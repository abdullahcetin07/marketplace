<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\OrderReturnContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Events\ReturnRequested;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\ReturnRequest;

/**
 * "İade etmek istiyorum" — the buyer asks (ADR-073).
 *
 * **THIS IS THE BUTTON THAT USED TO REFUND.** ADR-064 treated the return window as
 * the approval: a buyer inside it got their money the moment they tapped. For
 * physical goods that is paying out on trust — the parcel may or may not come
 * back, and the seller is made whole never. So the tap now writes a `requested`
 * row and **nothing else moves**: no money, no stock, no order status.
 *
 * It is the post-delivery mirror of `RequestOrderCancellationAction`, and the
 * gates are the mirror image too:
 *
 *   DELIVERED  and inside the return window — asked as ONE question of the Core
 *              return port, because a window exists only where a delivery
 *              happened (S3). Where the cancellation asks "has it NOT shipped",
 *              this asks "has it ARRIVED"; they are the two halves of one
 *              lifecycle and no order satisfies both.
 *   QUANTITY   every requested line is capped by what is still returnable, read
 *              through the same port the seller's completion re-checks. Asking
 *              for three of two is refused HERE as well as there — a buyer should
 *              not learn days later that the request they wrote was impossible.
 *   ONE OPEN   no request already running. `Approved` counts as running: the
 *              buyer is walking to the cargo desk, and a second request for that
 *              order is a mistake rather than a new intention.
 *
 * **IT IS NOT IDEMPOTENT, DELIBERATELY** — the same reasoning C2 states. A second
 * tap while one is open is a refusal, because silence would read as "sent again"
 * and a buyer who believes they have nudged somebody has been misled.
 *
 * **THE QUANTITIES ARE VALIDATED, NOT TRUSTED, AND THEN VALIDATED AGAIN.** What is
 * written here is the buyer's ASK; what actually moves is decided when the seller
 * completes, because a request can sit for days and an admin may refund one of its
 * lines meanwhile.
 *
 * @see docs/modules/Order.md §3.6
 */
final class CreateReturnRequestAction extends BaseAction
{
    /** Held between `handle()` and `after()` so the event fires AFTER COMMIT. */
    private ?ReturnRequest $requested = null;

    public function __construct(private readonly OrderReturnContract $returns) {}

    public function handle(mixed ...$arguments): ReturnRequest
    {
        /** @var Order $order */
        $order = $arguments[0];
        /** @var array<string, int> $lineQuantities */
        $lineQuantities = $arguments[1];
        $customerId = (int) $arguments[2];
        $reason = $arguments[3] ?? null;

        if ($order->status !== OrderStatus::Delivered) {
            /*
            | NOT DELIVERED IS NOT A RETURN. A parcel still in transit is a
            | CANCELLATION — a different operation, with a different gate and
            | different consequences for the seller's stock (ADR-065). Sending a
            | buyer down this path would refund goods nobody has received.
            */
            throw OrderException::notReturnable($order->uuid);
        }

        if (! $this->returns->isReturnOpen($order->uuid)) {
            // The window closed, or the delivery never registered. One answer for
            // both — the difference is the seller's business, not the buyer's to
            // probe.
            throw OrderException::returnWindowClosed($order->uuid);
        }

        if (ReturnRequest::query()->forOrder($order->uuid)->open()->exists()) {
            throw OrderException::returnAlreadyRequested($order->uuid);
        }

        $quantities = $this->validated($order->uuid, $lineQuantities);

        $request = ReturnRequest::query()->create([
            'order_uuid' => $order->uuid,
            'requested_by' => $customerId,
            'customer_id' => $order->customer_id,
            'reason' => $reason,
            'status' => ReturnRequestStatus::Requested,
            'line_quantities' => $quantities,
        ]);

        $this->requested = $request;

        return $request;
    }

    /**
     * Dispatched AFTER COMMIT — a notification listener must never announce a
     * request a later failure rolls back. No listener ships in v1.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->requested !== null) {
            event(new ReturnRequested(
                returnRequestUuid: $this->requested->uuid,
                orderUuid: $this->requested->order_uuid,
                customerId: $this->requested->customer_id,
            ));
        }
    }

    /**
     * Every asked-for line, capped by what is still returnable.
     *
     * **A REFUSAL, NEVER A CLAMP** — the rule S4 set for quantities and this keeps.
     * Silently reducing "three" to "two" would tell the buyer their whole return
     * was accepted and then send back part of it.
     *
     * @param array<string, int> $asked
     *
     * @return array<string, int>
     */
    private function validated(string $orderUuid, array $asked): array
    {
        $returnable = $this->returns->returnableQuantities($orderUuid);
        $quantities = [];

        foreach ($asked as $lineUuid => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            if ($quantity > ($returnable[$lineUuid] ?? 0)) {
                throw OrderException::notReturnable($orderUuid);
            }

            $quantities[$lineUuid] = $quantity;
        }

        if ($quantities === []) {
            // A return of nothing. Refused rather than written, because an empty
            // request would sit in a seller's queue meaning nothing.
            throw OrderException::notReturnable($orderUuid);
        }

        return $quantities;
    }
}
