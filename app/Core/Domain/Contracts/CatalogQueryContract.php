<?php

declare(strict_types=1);

namespace App\Core\Domain\Contracts;

/**
 * The read port other modules use to ask about the catalog — WITHOUT importing
 * the Catalog module (ADR-040, reaffirming ADR-033).
 *
 * The sibling of `StoreQueryContract`. Offer, Inventory, Search and the
 * Storefront reference a product or variant by UUID and ask their questions
 * here; Catalog provides the implementation and stays the single source of truth
 * for what is in the catalog. They never `use App\Modules\Catalog\...`.
 *
 * DELIBERATELY MINIMAL — exists / is-sellable / what-does-this-variant-belong-to
 * — the least a downstream module needs to validate a reference before acting on
 * it. Anything richer (a product page, a facet list) is a read surface of its
 * own, not this contract.
 *
 * THE QUESTION THIS CONTRACT DOES NOT ANSWER: price and stock. There is no such
 * data in the Catalog (ADR-037); asking Offer or Inventory is the whole point of
 * those being separate modules.
 *
 * @see App\Modules\Catalog\Infrastructure\Queries\CatalogQuery
 */
interface CatalogQueryContract
{
    /**
     * Whether a product with this UUID exists (and is not soft-deleted).
     */
    public function productExists(string $productUuid): bool;

    /**
     * Whether the product is published — the state in which downstream contexts
     * may act on it. From the Offer sprint this is the precondition for
     * attaching an offer; a draft, rejected or archived product must not become
     * sellable through the side door.
     */
    public function isProductPublished(string $productUuid): bool;

    /**
     * Whether a variant with this UUID exists (and is not soft-deleted).
     *
     * The variant, not the product, is what Offer and Inventory hold (ADR-039),
     * so this is the existence check those modules actually make.
     */
    public function variantExists(string $variantUuid): bool;

    /**
     * The UUID of the product a variant belongs to; null when no such variant
     * exists. Lets a caller check the parent's publication state without
     * carrying both identifiers.
     */
    public function productUuidForVariant(string $variantUuid): ?string;

    /**
     * Whether a category with this UUID exists and is active.
     */
    public function categoryExists(string $categoryUuid): bool;

    /**
     * Whether an attribute with this UUID exists and is active.
     */
    public function attributeExists(string $attributeUuid): bool;

    /**
     * The UUID of the organization that proposed a product, for provenance
     * checks; null when the product does not exist or was authored by staff
     * (ADR-040 — a bare uuid, never an Organization model).
     */
    public function proposingOrganizationUuidFor(string $productUuid): ?string;
}
