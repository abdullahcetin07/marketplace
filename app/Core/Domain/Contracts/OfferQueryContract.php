<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The read port other modules use to ask about offers — WITHOUT importing the
 * Offer module (ADR-046, reaffirming ADR-033/040).
 *
 * The third of its family, after `StoreQueryContract` and `CatalogQueryContract`.
 * Order will validate a line against it, Search will index through it, and the
 * storefront reads a store's listings through it. None of them ever
 * `use App\Modules\Offer\...`.
 *
 * IT ANSWERS THE QUESTION THE CATALOG CANNOT: price and stock. There is no such
 * data in the Catalog by design (ADR-037) — asking here is the whole point of
 * Offer being a separate module.
 *
 * RETURNS PLAIN ARRAYS, NEVER ELOQUENT MODELS, so a caller cannot reach through
 * a returned object into Offer's internals — the same boundary rule the sibling
 * contracts follow. Money crosses as `price_minor` (integer minor units,
 * non-negotiable #6) plus the currency code; formatting it as a decimal string is
 * the caller's presentation concern, and the integer never reaches a payload.
 *
 * THE SHAPE OF AN OFFER ROW returned by every list method below:
 *
 *     array{
 *       uuid: string,
 *       variant_uuid: string,
 *       product_uuid: string,
 *       selling_org_uuid: string,
 *       store_uuid: string,
 *       price_minor: int,
 *       list_price_minor: int|null,
 *       currency_code: string,
 *       stock_quantity: int,
 *       in_stock: bool,
 *       created_at: string,
 *     }
 *
 * The buy box is COMPUTED on every call, never stored (ADR-045). These methods
 * are therefore ordinary indexed reads with no cache to invalidate — which is
 * exactly the trade that decision bought.
 *
 * @see App\Modules\Offer\Infrastructure\Queries\OfferQuery
 * @see docs/modules/Offer.md §8.1
 */
interface OfferQueryContract
{
    /**
     * Whether an offer with this UUID exists and has not been withdrawn.
     *
     * The existence check a future Order makes before accepting a line: a
     * withdrawn offer's row survives for historical order lines, but nothing may
     * start selling from it again.
     */
    public function offerExists(string $offerUuid): bool;

    /**
     * Every offer eligible for a product's buy box, cheapest first, ties broken
     * by earliest `created_at` (§5).
     *
     * Eligible means Active, in stock, and on an Active store. The first element
     * is therefore the featured offer, and `featuredOfferForProduct()` is the
     * same question asked for one row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeOffersForProduct(string $productUuid): array;

    /**
     * The winning offer for a product, or null when nothing is sellable — the
     * product exists in the catalog but no one is currently offering it in
     * stock.
     *
     * @return array<string, mixed>|null
     */
    public function featuredOfferForProduct(string $productUuid): ?array;

    /**
     * Eligible offers for one variant, cheapest first. The variant-level
     * question, for callers that already resolved a SKU (ADR-039) rather than a
     * product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeOffersForVariant(string $variantUuid): array;

    /**
     * A store's own active offers — the storefront contribution's data source
     * (ADR-046).
     *
     * @return array<int, array<string, mixed>>
     */
    public function offersForStore(string $storeUuid): array;

    /**
     * ONE offer by uuid, in the row shape above, or null when it is not sellable
     * right now.
     *
     * ADDED FOR ORDER (Order.md §1.4 — "validate an offer is active, read its
     * live price for the cart, snapshot it at checkout. By uuid"). Every other
     * method here answers a LIST question — what can I buy for this product,
     * what does this store sell — because until Order existed, every caller
     * arrived holding a product or a store. A cart line arrives holding an offer,
     * and there was no way to ask about one.
     *
     * IT APPLIES THE SAME ELIGIBILITY AS THE BUY BOX, deliberately: Active, on a
     * live store, and available per Inventory. So "can this go in a basket" and
     * "is this what a product page would feature" cannot drift apart — a shopper
     * must never be able to add something the platform would not show them.
     *
     * NULL RATHER THAN A REASON. A paused offer, a suspended one, a closed shop
     * and a sold-out shelf are the same fact from a buyer's side ("you cannot buy
     * this"), and enumerating a seller's internal state to a shopper leaks how the
     * platform works without helping them. `offerExists()` remains the separate,
     * weaker question — does the row exist at all — which is what a HISTORICAL
     * order line needs, since it may reference an offer nobody may buy from today.
     *
     * @return array<string, mixed>|null
     */
    public function activeOfferByUuid(string $offerUuid): ?array;

    /**
     * Which of these products can actually be bought right now — the SELLABLE
     * filter the public catalogue browse composes with (ADR-058).
     *
     * ADDED FOR THE STOREFRONT. A buyer's listing must not show a product nobody
     * is selling: the catalogue is shared and holds entries no merchant has
     * offered, or has offered and run out of. Catalog cannot answer that — it has
     * no price and no stock (ADR-037) — so it asks here and narrows its own query.
     *
     * SAME ELIGIBILITY AS THE BUY BOX: Active, on a live store, available per
     * Inventory. So "does this appear in the listing" and "does this have a buy
     * box" cannot drift, which is what stops a shopper clicking a card into a
     * page that says unavailable.
     *
     * AN EMPTY ARGUMENT MEANS "ALL", deliberately: the browse needs the whole
     * sellable set before it can paginate correctly — filtering after pagination
     * would give pages of variable size and a wrong total. That set is bounded by
     * the catalogue, and the cost of it growing is documented where the browse
     * uses it.
     *
     * @param  array<int, string>  $productUuids  empty = every sellable product
     * @return array<int, string>  product uuids, in no guaranteed order
     */
    public function sellableProductUuids(array $productUuids = []): array;

    /**
     * The buy-box price of each product, in one round trip (ADR-058).
     *
     * WHAT A LISTING NEEDS TO SAY "₺X'den başlayan fiyatlarla". Asking per card
     * would be one query per row on the platform's busiest page; asking here is
     * one call for the page.
     *
     * Products with no eligible offer are ABSENT from the result rather than
     * present with a null price — a caller iterating the result gets only things
     * it can render a price for, and an unknown uuid leaks nothing by looking the
     * same as an unsold one.
     *
     * `in_stock` is always true for a returned row, by construction: an entry
     * exists precisely because something is available. It is on the shape anyway
     * so the payload stays honest when a future eligibility rule (pre-orders,
     * backorders) makes the two come apart.
     *
     * `seller_count` AND `list_price_minor` WERE ADDED FOR THE LISTING CARD
     * (2026-08-01), and both are here rather than in a second call for the same
     * reason the price is: a card that needs three round trips is a card that
     * renders three times.
     *
     * `seller_count` is how many merchants a buyer could pick between — the
     * ELIGIBLE ones, the same set the buy box ranks, so "3 satıcı" and the three
     * rows on the product page cannot disagree. Sellers who are paused, suspended,
     * closed or sold out are not a choice and are not counted.
     *
     * `list_price_minor` belongs to the WINNING offer, not to the product: there
     * is no such thing as the product's list price on a shared catalogue, only
     * this seller's claim about what they discounted from. Null when the winner
     * declared none, which is most of them — a card renders a struck-through price
     * only when a seller actually made that claim.
     *
     * @param  array<int, string>  $productUuids
     * @return array<string, array{price_minor: int, list_price_minor: int|null, currency_code: string, in_stock: bool, seller_count: int}>
     */
    public function buyBoxPricesFor(array $productUuids): array;
}
