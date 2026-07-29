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
}
