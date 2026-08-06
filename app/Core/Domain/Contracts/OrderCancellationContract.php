<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The command port a seller's panel drives to cancel part of a PAID order
 * (ADR-065, C1).
 *
 * **THE PLATFORM'S SECOND COMMAND PORT, AND IT NEEDED A REASON AS GOOD AS THE
 * FIRST'S.** `InventoryReservationContract` exists because stock is a resource
 * other contexts legitimately borrow. This exists because of a narrower fact: a
 * seller cancels a line from the screen where they see their orders, that screen
 * belongs to Order, and the refund it has to perform belongs to Payment. Order
 * imports no module and neither does Payment, so something has to sit between
 * them, and an event cannot: the seller must be told *immediately* that they
 * asked for three of two, or that the parcel already shipped. Events announce
 * facts; this asks a question and waits for the answer.
 *
 * **IT IS A REQUEST, NOT A REACH-IN.** The caller names an order, a seller and
 * how many of which lines. It does not say what that costs, which ledger entries
 * to write, or what the order's new status is — every one of those is decided
 * behind the port, by the same `RefundLinesAction` a buyer's return uses (ADR-065:
 * reuse, not reinvention). A port that returned a refund model would be Order
 * reaching into Payment through a keyhole.
 *
 * **THE SELLER ORG IS A PARAMETER, NOT AN ASSUMPTION.** The implementation
 * verifies the order actually belongs to it and refuses otherwise, because the
 * caller is a panel and a panel's tenancy scope is a query somebody can get
 * wrong. Authorization is checked on both sides of this port deliberately.
 *
 * MONEY NEVER CROSSES IT. There is no amount here and there is not going to be
 * one: a cancellation names lines and quantities, exactly as a return does
 * (Payment.md §8), and the platform prices it from the frozen snapshot.
 *
 * @see App\Modules\Payment\Infrastructure\Commands\OrderCancellation
 * @see docs/modules/Payment.md §8
 */
interface OrderCancellationContract
{
    /**
     * Cancel `$quantities` units of one paid, unshipped order's lines, refunding
     * the buyer and restocking the seller.
     *
     * Throws when the order is not the seller's, when its parcel is no longer
     * awaiting handover, when nothing may be cancelled, or when the PSP refuses.
     * A caller must surface the failure — a silent no-op here is a seller who
     * believes they have shed a line they are still expected to ship.
     *
     * @param array<string, int> $quantities order line uuid => how many to cancel
     */
    public function cancelLinesBySeller(
        string $orderUuid,
        string $sellerOrgUuid,
        array $quantities,
        ?string $reason = null,
        ?int $actorId = null,
    ): void;

    /**
     * How many units of each line may still be cancelled — line uuid => count,
     * omitting the lines with nothing left.
     *
     * **A READ ON A COMMAND PORT, AND IT EARNS ITS PLACE.** The form that drives
     * the command has to cap each input, and the cap is a subtraction only
     * Payment can do: the order knows what was bought, and Payment knows what has
     * already gone back. Order asking a second port for half of one answer would
     * be two round trips to build one number.
     *
     * IT IS NOT THE GUARD. `RefundableLines` re-checks every quantity behind the
     * port, because a form is a suggestion and two sellers can hold the same
     * screen open. This exists so the honest case does not have to fail to learn
     * its own limits.
     *
     * Empty when the order is not this seller's, has no payment, or is no longer
     * cancellable — so a caller that renders nothing renders the right thing.
     *
     * @return array<string, int>
     */
    public function cancellableQuantities(string $orderUuid, string $sellerOrgUuid): array;
}
