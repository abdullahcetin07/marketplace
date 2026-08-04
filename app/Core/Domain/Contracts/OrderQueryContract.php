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
     * The paying customer behind a checkout group, as the ADR-040 id/uuid pair;
     * null when the group does not exist.
     *
     * ADDED FOR PAYMENT (2026-08-04). A Payment row is keyed to the GROUP but has
     * to know whose money it is — to scope the owner-only status read, and to tell
     * the PSP who is paying. The id is what scopes; the uuid is what may leave the
     * application (non-negotiable #7).
     *
     * @return array{id: int, uuid: string, email: string}|null
     */
    public function checkoutGroupCustomer(string $checkoutGroupUuid): ?array;

    /**
     * One order's frozen line snapshots (ADR-053).
     *
     * ADDED FOR PAYMENT (2026-08-04, Payment.md §12). Two callers need it and
     * neither may import Order: the PSP basket a buyer sees on the payment page,
     * and — from P2 — the commission resolver, which reads product/brand/category
     * off the SNAPSHOT rather than off the live catalogue, so a re-categorised
     * product cannot move a settled commission.
     *
     * SNAPSHOT VALUES, NOT LIVE ONES. Everything here was frozen at placement and
     * is what the customer agreed to; asking the Catalog again would answer a
     * different question.
     *
     * @return array<int, array{variant_uuid: string, product_uuid: string, title: string, quantity: int, unit_price_minor: int, line_total_minor: int, tax_rate: string}>
     */
    public function orderLines(string $orderUuid): array;

    /**
     * The stock-reservation references one order is holding, in Inventory's
     * vocabulary — ready to hand straight to `InventoryReservationContract`.
     *
     * ADDED FOR PAYMENT (2026-08-04), and the shape is the point. Payment must
     * COMMIT what placement only held (ADR-057), which means naming the
     * reservations — but the key's FORMAT is Order's (`{order_uuid}:{variant_uuid}`,
     * the ADR-049 amendment). Handing back the assembled references keeps that
     * format in the module that invented it; the alternative is Payment
     * reconstructing a string it does not own, and the two drifting apart the day
     * the scheme changes.
     *
     * Empty for an order with no lines, and for one that never reserved.
     *
     * @return array<int, string>
     */
    public function reservationReferencesFor(string $orderUuid): array;

    /**
     * Who sells this order, and what the platform froze as its commission — the
     * two numbers a seller's ledger entry is made of. Null when no such order
     * exists.
     *
     * ADDED FOR PAYMENT'S LEDGER (2026-08-04, ADR-062). A paid order produces two
     * entries per seller: a credit of what they earned and a debit of the
     * platform's share. The first comes from `orderTotals()`; this supplies the
     * seller it belongs to and the commission that was FROZEN onto the lines at
     * payment (ADR-061).
     *
     * IT READS THE SNAPSHOT, IT DOES NOT RECOMPUTE. That is the whole reason the
     * method exists rather than Payment resolving the rules a second time: the
     * ledger and the line snapshots must agree to the kuruş, forever, and two
     * computations of one number is exactly how they stop agreeing. `Σ` of the
     * lines' `commission_minor`.
     *
     * `commission_minor` IS NULL UNTIL THE COMMISSION IS FROZEN, and a caller must
     * treat that as "not settled yet" rather than as zero — crediting a seller
     * their full sale with no commission taken is the expensive way to read it
     * wrong.
     *
     * @return array{selling_org_uuid: string, commission_minor: int|null}|null
     */
    public function orderSettlement(string $orderUuid): ?array;

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
