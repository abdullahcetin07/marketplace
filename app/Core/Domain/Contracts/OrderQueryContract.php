<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The read port other modules use to ask about orders — WITHOUT importing the
 * Order module (Order.md §5, reaffirming ADR-033/040).
 *
 * THE SIBLING OF `OfferQueryContract` AND `InventoryQueryContract`, and it exists
 * for the same reason those did before their consumers arrived: Payment and
 * Shipping do not exist yet, and the point of publishing the port now is that
 * when they do, the answer to "how do I read an order" is already decided and is
 * not "import the model".
 *
 * WHAT PAYMENT WILL ACTUALLY NEED, which is what shaped these four methods:
 *
 *   - does this order exist, and is it in a state I may charge for
 *   - what does the customer owe, and in what currency
 *   - which orders make up ONE purchase (ADR-052) — because a customer pays once
 *     for a checkout group of N seller orders, and reconciling one charge against
 *     many orders is the single hardest consequence of the split
 *
 * RETURNS PLAIN ARRAYS AND SCALARS, never models — the discipline every Core
 * query contract keeps, so a foreign module cannot reach through the port into
 * Order's internals.
 *
 * MONEY CROSSES AS INTEGER MINOR UNITS plus a currency code (non-negotiable #6).
 * Rendering it as a decimal string is the caller's presentation concern, exactly
 * as it is for `OfferQueryContract`.
 *
 * INTERNAL IDS NEVER APPEAR HERE (non-negotiable #7). Every identifier in and out
 * is a uuid.
 *
 * THERE IS NO COMMAND HALF, deliberately. Nothing outside Order may place,
 * cancel or re-price an order — when Payment needs the status to move it will
 * raise its own event and Order will react, because the module that owns the
 * lifecycle owns the transitions. Compare `InventoryReservationContract`, which
 * IS a command port: stock is a resource other contexts legitimately borrow,
 * while an order's state machine is not something anyone else may drive.
 *
 * @see App\Modules\Order\Infrastructure\Queries\OrderQuery
 * @see docs/modules/Order.md §5
 */
interface OrderQueryContract
{
    /**
     * Whether an order with this UUID exists.
     */
    public function orderExists(string $orderUuid): bool;

    /**
     * The order's status as its string value (`pending`, `awaiting_payment`,
     * `cancelled`); null when no such order exists.
     *
     * A STRING RATHER THAN THE ENUM, because the enum is Order's and typing the
     * contract with it would make every consumer import the module this port
     * exists to avoid importing. The values are stable — they are what the column
     * holds.
     */
    public function orderStatus(string $orderUuid): ?string;

    /**
     * The uuids of every order in one checkout group, in creation order
     * (ADR-052).
     *
     * THE METHOD THE SPLIT MAKES NECESSARY. A customer pays once for what the
     * platform stores as N orders, so anything acting on "the purchase" —
     * charging it, refunding it, showing it on a receipt — starts here.
     *
     * @return array<int, string>
     */
    public function ordersForCheckoutGroup(string $checkoutGroupUuid): array;

    /**
     * What one order comes to; null when no such order exists.
     *
     * The tax total is included rather than left to be recomputed: it was
     * extracted at placement from rates that may since have changed (ADR-053/055),
     * so recomputing it later is not guaranteed to reproduce the invoice.
     *
     * @return array{items_total_minor: int, tax_total_minor: int, grand_total_minor: int, currency_code: string}|null
     */
    public function orderTotals(string $orderUuid): ?array;
}
