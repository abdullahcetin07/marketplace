<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * "How many of this can that seller actually sell right now?" — WITHOUT
 * importing the Inventory module (ADR-048, reaffirming ADR-033).
 *
 * The fourth of its family, after Store, Catalog and Offer. Its first caller is
 * the buy box: Offer's in-stock test reads availability here rather than its own
 * `stock_quantity` column, so a unit held for somebody's checkout stops being
 * offered to the next shopper without Offer knowing what a reservation is.
 *
 * IT ANSWERS THE QUESTION THE OFFER CANNOT. `Offer.stock_quantity` is what the
 * SELLER DECLARED they have — the input, still typed on the Offer form by owner
 * decision. Availability is `on_hand − reserved`, and only Inventory knows the
 * second term. Until Order exists `reserved` is always 0 and the two agree
 * exactly; the point is that reservations now have somewhere to flow.
 *
 * SCALARS ONLY, never Eloquent models — the boundary rule every Core query
 * contract follows. Counts, not money: the minor-units rule does not apply here
 * (Inventory.md §8).
 *
 * A seller with no stock record for a variant is not an error. It means they
 * never listed it, and every method below answers 0 / false rather than
 * throwing — a buy box asking about something nobody sells is an ordinary read.
 *
 * @see App\Modules\Inventory\Infrastructure\Queries\InventoryQuery
 * @see docs/modules/Inventory.md §5.1
 */
interface InventoryQueryContract
{
    /**
     * Units this seller can sell this instant: `on_hand − reserved`, floored at
     * zero. 0 when no stock record exists.
     */
    public function availableFor(string $variantUuid, string $sellingOrgUuid): int;

    /**
     * Whether at least `$quantity` can be sold — the buy box's in-stock test,
     * and the question a cart asks before it lets somebody proceed.
     *
     * A separate method rather than `availableFor(...) >= $qty` at every call
     * site, so the definition of "available enough" lives in one place if it
     * ever grows a nuance (a safety buffer, a per-seller floor).
     */
    public function isAvailable(string $variantUuid, string $sellingOrgUuid, int $quantity = 1): bool;

    /**
     * Units physically held, ignoring reservations. 0 when no record exists.
     *
     * Distinct from availability on purpose: a seller's own stock view shows
     * both, because "I have 10 but can only sell 7" is the sentence
     * reservations exist to make sayable.
     */
    public function onHandFor(string $variantUuid, string $sellingOrgUuid): int;
}
