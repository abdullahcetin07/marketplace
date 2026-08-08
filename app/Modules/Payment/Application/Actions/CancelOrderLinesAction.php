<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Domain\Contracts\ShipmentQueryContract;
use App\Modules\Payment\Domain\DTOs\ReturnRequestDTO;
use App\Modules\Payment\Domain\Enums\RefundCause;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\PaymentRefundLine;
use App\Shared\Support\PublicKey;

/**
 * "Bunu gönderemeyeceğim" — the seller sheds a line before handover (ADR-065, C1).
 *
 * **THE MIRROR OF `CompleteReturnAction`, AND DELIBERATELY THE SAME SHAPE.** That
 * one is the buyer's door onto `RefundLinesAction`, gated on ownership, delivery
 * and a clock. This is the seller's, gated on ownership and the parcel not having
 * left. Neither touches money: they check who is asking and whether the moment is
 * right, and then hand the same action the same DTO. Two doors, one machine — a
 * second refund implementation is how two code paths for one number stop agreeing.
 *
 * THREE THINGS ARE CHECKED HERE AND NOWHERE ELSE:
 *
 *   OWNERSHIP  the order is sold BY this seller org. Asked of Order through the
 *              Core port, because a payment knows whose money it took and a
 *              cancellation needs to know whose ORDER it is. It is checked here
 *              even though the panel already scoped its query — a panel's tenancy
 *              is a query somebody can get wrong, and this is the side of the
 *              port that cannot be.
 *   UNSHIPPED  the parcel is still awaiting handover. **This is ADR-065's whole
 *              gate**, and it is a shipment state rather than a time window: once
 *              the goods are with a carrier the seller has spent the effort and
 *              the buyer's route is the return (ADR-064). A missing shipment is a
 *              REFUSAL, not an assumption — @see `ShipmentQueryContract`.
 *   QUANTITY   handled by `RefundableLines`, unchanged: a line goes back up to
 *              what has not already gone back, and asking for three of two is a
 *              refusal rather than a clamp.
 *
 * **IT DOES NOT MOVE THE ORDER OR THE PARCEL.** `RefundLinesAction` announces
 * `PaymentRefunded` with `cause = cancellation`, and Order and Shipping each move
 * their own state from it. Payment setting an order's status would be the same
 * boundary violation whichever end of the lifecycle it happened at.
 *
 * ONE ANSWER FOR EVERY REFUSAL. "Not yours", "already shipped", "no such order"
 * all come back alike, because the alternative lets a seller discover which order
 * uuids are real and what has happened to them by watching which error changes.
 *
 * @see docs/modules/Payment.md §8
 */
final class CancelOrderLinesAction
{
    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly ShipmentQueryContract $shipments,
        private readonly RefundLinesAction $refund,
    ) {}

    /**
     * NOT A `BaseAction`, and deliberately: it owns no transaction because it
     * makes no writes. `RefundLinesAction` owns the one transaction there is, and
     * nesting a second would only obscure where the rollback boundary lies. The
     * same reasoning `CompleteReturnAction` states.
     *
     * @param array<string, int> $quantities order line uuid => units to cancel
     */
    public function run(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?string $reason = null,
        ?int $actorId = null,
    ): PaymentRefund {
        $this->assertSoldBy($orderUuid, $sellerOrgUuid);
        $this->assertAwaitingHandover($orderUuid);

        return $this->refund->run(new ReturnRequestDTO(
            orderUuid: $orderUuid,
            quantities: $quantities,
            reason: $reason,
            actorId: $actorId,
            // THE ONE FIELD THAT MAKES THIS A CANCELLATION. Everything below this
            // line is the return's machinery, untouched (ADR-065: reuse).
            cause: RefundCause::Cancellation,
        ));
    }

    /**
     * What is still cancellable on this order — line uuid => units.
     *
     * THE FORM'S CAPS, NOT THE GUARD. `RefundableLines` re-checks every quantity
     * inside the refund, because two sellers can hold the same screen open and a
     * form is a suggestion. This exists so the honest case does not have to fail
     * in order to learn its own limits.
     *
     * EMPTY WHEN THE ORDER IS NOT CANCELLABLE AT ALL — not yours, already shipped
     * — so a panel that renders nothing renders the right thing, and the refusal
     * is still the action's to make if somebody posts anyway.
     *
     * @return array<string, int>
     */
    public function cancellable(string $orderUuid, string $sellerOrgUuid): array
    {
        if (! PublicKey::looksLikeUuid($orderUuid) || $sellerOrgUuid === '') {
            return [];
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['selling_org_uuid'] !== $sellerOrgUuid) {
            return [];
        }

        if (! $this->shipments->isAwaitingHandover($orderUuid)) {
            return [];
        }

        $remaining = [];

        foreach ($this->orders->orderLines($orderUuid) as $line) {
            $left = max(0, $line['quantity'] - PaymentRefundLine::refundedQuantityFor($line['id']));

            if ($left > 0) {
                $remaining[$line['id']] = $left;
            }
        }

        return $remaining;
    }

    private function assertSoldBy(string $orderUuid, string $sellerOrgUuid): void
    {
        /*
        | BY SHAPE FIRST (ADR-059). `shipments.order_uuid` and
        | `settlement_windows.order_uuid` are native uuid columns on PostgreSQL,
        | so a non-uuid value would be SQLSTATE[22P02] — a 500 on a panel button
        | — while SQLite quietly returns nothing. The trap, twelfth watch.
        */
        if (! PublicKey::looksLikeUuid($orderUuid) || $sellerOrgUuid === '') {
            throw PaymentException::notCancellable($orderUuid);
        }

        $fulfilment = $this->orders->orderFulfilment($orderUuid);

        if ($fulfilment === null || $fulfilment['selling_org_uuid'] !== $sellerOrgUuid) {
            throw PaymentException::notCancellable($orderUuid);
        }
    }

    /**
     * The parcel has not left yet.
     *
     * A MISSING SHIPMENT REFUSES, which is the conservative half of ADR-065's
     * gate: an order whose shipment row never arrived cannot prove it is still on
     * the shelf, and refunding a parcel already with a carrier is the one mistake
     * here that cannot be undone from this side. `shipping:backfill` is the fix,
     * and it is a smaller problem than the alternative.
     */
    private function assertAwaitingHandover(string $orderUuid): void
    {
        if (! $this->shipments->isAwaitingHandover($orderUuid)) {
            throw PaymentException::notCancellable($orderUuid);
        }
    }
}
