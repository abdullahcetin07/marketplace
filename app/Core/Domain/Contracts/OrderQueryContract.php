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
     * Which checkout group one order belongs to; null when no such order exists.
     *
     * ADDED FOR S4 (2026-08-06), AND IT IS THE INVERSE OF THE METHOD ABOVE. A
     * buyer returning an item names their ORDER — that is what "Siparişlerim"
     * shows them — while a refund is made against the PAYMENT, which is keyed to
     * the group (ADR-052/060). Something has to cross that gap.
     *
     * IT EXISTS BECAUSE THE DERIVATION WAS TOO EXPENSIVE TO KEEP. Payment can in
     * principle find the group by walking `ordersForCheckoutGroup()` over its own
     * settled payments until the order appears — and did, briefly — but that is a
     * scan of every settled payment on the platform plus a query per payment, on
     * an endpoint the customer taps. "Derivable from what the port already
     * exposes" stopped being an argument for not adding it once the derivation
     * cost that.
     *
     * THE COLUMN IS ORDER'S OWN. No new fact crosses the boundary: the group uuid
     * is already on the order and already leaves through `ordersForCheckoutGroup()`
     * in the other direction.
     */
    public function checkoutGroupFor(string $orderUuid): ?string;

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
     * `id` AND `commission_minor` JOINED IN S4 (2026-08-06), when a refund became
     * line-level: a return names a LINE and a quantity, and reversing the
     * commission proportionally means reading the figure that was frozen rather
     * than resolving the rules again. `commission_minor` is null until payment
     * settles it, and a caller must treat that as "not settled" rather than zero.
     *
     * @return array<int, array{id: string, variant_uuid: string, product_uuid: string, title: string, quantity: int, unit_price_minor: int, line_total_minor: int, tax_rate: string, commission_minor: int|null}>
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
     * **KEYED BY VARIANT UUID SINCE S4 (2026-08-06).** P5's refund was whole-order
     * and wanted every reference, so a list was enough. A line-level refund needs
     * the reference for ONE variant — and a caller that cannot look one up builds
     * `{order_uuid}:{variant_uuid}` itself, which is exactly the drift the
     * paragraph above exists to prevent. A `foreach` over the values is
     * unaffected, which is why the shape changed rather than a second method
     * being added.
     *
     * @return array<string, string> variant uuid => reservation reference
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
     * Who has to ship this order, and what to call it on a packing list; null
     * when no such order exists.
     *
     * ADDED FOR SHIPPING (2026-08-05, S1). A shipment is created from a payment
     * event that names only order uuids, and it needs the SELLER — a parcel
     * belongs to whoever has to put it in a box. The order number comes with it
     * because every fulfilment surface labels a parcel by the order it fulfils,
     * and asking per row for a string that can never change (ADR-053) would be a
     * query per row for a constant.
     *
     * IT IS DELIBERATELY NOT `orderSettlement()`, which already returns the
     * seller. That method's other half is a commission, and a fulfilment caller
     * reading a money field to find out who packs a box is the kind of reuse that
     * makes a later change to either one break the other.
     *
     * THE STATUS IS A STRING for the reason `orderStatus()` gives: the enum is
     * Order's, and typing the contract with it would make every consumer import
     * the module this port exists to avoid importing.
     *
     * `customer_id` JOINED IT IN S2 (2026-08-05), when a buyer got a button. Who
     * RECEIVES the parcel is as much a fulfilment fact as who sends it, and a
     * shipment cannot answer it — it carries the seller, deliberately. The
     * internal id rather than the uuid because it is compared against the
     * authenticated actor's key and never leaves the application (non-negotiable
     * #7).
     *
     * @return array{order_number: string, selling_org_uuid: string, customer_id: int, status: string}|null
     */
    public function orderFulfilment(string $orderUuid): ?array;

    /**
     * Every order that has been PAID — optionally just one seller's.
     *
     * ADDED FOR SHIPPING (2026-08-05, S1). Shipping creates a parcel per paid
     * order from an event, which covers everything paid from now on; this covers
     * what was paid BEFORE the module existed, and any order whose event was lost.
     * It is what `shipping:backfill` reads.
     *
     * NEWEST FIRST AND CAPPED, because "every paid order" grows without bound and
     * a contract method that can return a million rows is one somebody eventually
     * calls in a request. A backfill that has to run twice is a smaller problem
     * than a read that cannot finish.
     *
     * @return array<int, string> order uuids
     */
    public function paidOrders(?string $sellerOrgUuid = null, int $limit = 500): array;

    /**
     * The delivered lines a customer holds for one product — the review gate
     * (ADR-067, Reviews.md §5).
     *
     * **IT RETURNS LINES, NOT A BOOLEAN**, and that is the shape the aggregate
     * forces: a review binds to ONE delivered order line, so the buyer's
     * "Değerlendir" screen has to show WHICH purchase it is offering to review,
     * and the seller tag the review will be stamped with comes from here. A
     * yes/no could not answer either question.
     *
     * **KEYED ON (CUSTOMER, PRODUCT)**, which no existing method on this port
     * answered — every other one is order-uuid-centric, because every earlier
     * consumer already knew which order it meant. Reviews starts from a product
     * page and a session.
     *
     * **DELIVERED ONLY.** The gate is delivery, not payment (ADR-067): "kullandım,
     * şöyleymiş" is the honesty a review promises, and a paid-but-unshipped order
     * has no experience to report yet. Empty when the buyer has none.
     *
     * TWO DEVIATIONS FROM Reviews.md §5, BOTH DELIBERATE AND BOTH RECORDED IN THE
     * AMENDMENT LOG:
     *
     *   THE CUSTOMER IS A UUID, not the internal id the spec sketched. `orders`
     *   carries and indexes `customer_uuid`, the caller (Reviews) stores both, and
     *   a port that can be satisfied without an internal id should be.
     *
     *   `purchased_at`, NOT `delivered_at`. There IS no `delivered_at` on an
     *   order: delivery is the status alone, and the timestamp lives on Shipping's
     *   `shipments` — a table Order must not read and Reviews may not either. The
     *   order's `placed_at` is the honest date this port can supply, and it is
     *   what a "purchases from the last N days" window would measure if v1 ever
     *   grows one. Naming it after what it IS avoids a caller trusting a delivery
     *   date that is really a purchase date.
     *
     * @return array<int, array{order_line_uuid: string, store_uuid: string, selling_org_uuid: string, variant_uuid: string|null, variant_label: string|null, product_title: string, purchased_at: string|null}>
     */
    public function deliveredPurchaseLines(string $customerUuid, string $productUuid): array;

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
