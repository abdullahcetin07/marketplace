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
}
