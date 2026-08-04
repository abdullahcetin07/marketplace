<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The "let me find something to sell" read port over the shared catalog
 * (Offer.md §8.2, ADR-046).
 *
 * WHY `CatalogQueryContract` COULD NOT GROW THESE. That contract is deliberately
 * a set of yes/no existence checks — is this variant real, is its product
 * published — the least a downstream module needs to validate a reference before
 * acting on it. Browsing is the opposite shape: a paginated, filtered list built
 * for a human to pick from. Bolting it onto the validation port would blur what
 * that port promises, so this is a second, separately-named contract with a
 * single audience.
 *
 * IMPLEMENTED BY CATALOG, CONSUMED BY OFFER. It is the one Catalog change the
 * Offer sprint makes — a read contract only, no schema change — which is exactly
 * why Catalog Phase 1 was left unfrozen (Catalog.md §15).
 *
 * PUBLISHED PRODUCTS ONLY. Every method here is scoped to what a seller may
 * legitimately offer against; a draft, rejected or archived product is invisible
 * through this port, so a caller cannot make a moderation state sellable through
 * the side door (§3.4).
 *
 * NO PRICE, NO STOCK — the Catalog has neither (ADR-037). This returns what a
 * thing IS; what it costs is the caller's own domain.
 *
 * @see App\Modules\Catalog\Infrastructure\Queries\CatalogBrowse
 * @see docs/modules/Offer.md §4, §8.2
 */
interface CatalogBrowseContract
{
    /**
     * Published products matching a free-text query, optionally narrowed to a
     * category subtree or a brand.
     *
     * A category filter includes DESCENDANTS: picking "Giyim" must find a
     * t-shirt filed under "Giyim > Üst Giyim > Tişört", because a seller thinks
     * in departments, not in leaf nodes.
     *
     * @return array{
     *     items: array<int, array{
     *         uuid: string,
     *         title: string,
     *         brand: string|null,
     *         category: string,
     *         gtin: string|null,
     *         variant_count: int,
     *     }>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int,
     * }
     */
    public function searchPublishedProducts(
        string $query = '',
        ?string $categoryUuid = null,
        ?string $brandUuid = null,
        int $page = 1,
        int $perPage = 20,
    ): array;

    /**
     * A published product's variants, with a human-readable combination label
     * for the pick step ("Kırmızı / M").
     *
     * The SKU is included because it is the handle a seller recognises from
     * their own systems. Returns an empty array when the product does not exist
     * or is not published — a caller must not be able to enumerate drafts.
     *
     * @return array<int, array{
     *     uuid: string,
     *     sku: string,
     *     label: string,
     *     is_default: bool,
     * }>
     */
    public function variantsForProduct(string $productUuid): array;

    /**
     * Display data for products already referenced by uuid, keyed by uuid.
     *
     * WHY A BATCH LOOKUP EXISTS AT ALL. A downstream module holds only uuids
     * (ADR-040) — an offer knows it prices `product_uuid`, not what that product
     * is called. Rendering a list of offers therefore needs a title per row, and
     * the two honest ways to get one are this or denormalizing the title onto
     * the offer. The copy is what ADR-037 exists to refuse: a title edited in
     * the catalog would silently disagree with every seller's stale copy.
     *
     * Batched rather than one-at-a-time so a page of rows is one query, not one
     * per row. Unknown or unpublished uuids are simply absent from the result —
     * a caller renders a fallback rather than getting a null-shaped entry.
     *
     * @param  array<int, string>  $productUuids
     * @return array<string, array{uuid: string, title: string, brand: string|null, category: string}>
     */
    public function productSummaries(array $productUuids): array;

    /**
     * The product uuid behind a public identifier — a uuid OR a flat slug
     * (ADR-059). Null when nothing published matches.
     *
     * ADDED FOR OFFER'S BUY BOX (2026-08-04). `/products/{idOrSlug}/offers` is a
     * PUBLIC storefront URL and the flat scheme means the segment is usually a
     * slug, but the slug registry is Catalog's and Offer may not import it
     * (`LayeringTest`). So Offer asks here, exactly as it already asks for a
     * product's title.
     *
     * IT EXISTS BECAUSE THE ALTERNATIVE WAS A CRASH, not an inconvenience.
     * Passing the raw segment into a `product_uuid` comparison is
     * `SQLSTATE[22P02]` on PostgreSQL — an unhandled 500 — and a silent false on
     * SQLite, which is why the whole suite stayed green through the platform's
     * previous THREE occurrences of the same shape (ADR-049's reservation
     * reference, the ADR-056 geo cascade, ADR-059's own listing filter). This was
     * the fourth.
     *
     * RESOLVED BY SHAPE on the Catalog side, so no caller has to know the rule —
     * which is the point of putting it behind the contract rather than making
     * every consumer remember to call `PublicKey` first.
     *
     * PUBLISHED ONLY, matching `productSummaries()` above: a caller that gets a
     * uuid from here can pass it straight on, and a draft's existence never leaks
     * through a resolvable slug.
     */
    public function publishedProductUuidFor(string $idOrSlug): ?string;

    /**
     * The same, for variants: the SKU and the combination label a human reads.
     *
     * `product_uuid` is included so a caller holding only a variant can group by
     * product without a second round trip.
     *
     * @param  array<int, string>  $variantUuids
     * @return array<string, array{uuid: string, product_uuid: string, sku: string, label: string}>
     */
    public function variantSummaries(array $variantUuids): array;
}
