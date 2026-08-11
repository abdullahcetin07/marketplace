<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Contracts;

use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence port for offers.
 *
 * THE TENANCY VOCABULARY LIVES HERE. `forOrganizations()` is what the seller
 * panel's scope wall reads (ADR-030) and `duplicateFor()` is what enforces one
 * live offer per (org, variant) in the application layer — the database's
 * partial unique index is the backstop under it, not a substitute for a
 * readable error.
 *
 * The buy box is deliberately NOT here: it is a cross-context read (it must ask
 * Store whether a storefront is live) and belongs to `OfferQuery`, which
 * implements the Core port. A repository answers questions about this module's
 * own rows.
 *
 * @see App\Modules\Offer\Infrastructure\Repositories\OfferRepository
 */
interface OfferRepositoryContract
{
    public function findByUuid(string $uuid): ?Offer;

    public function findOrFailByUuid(string $uuid): Offer;

    /**
     * The offer that would collide with a new one for this (org, variant) —
     * anything not withdrawn (§3.2). Null when the seller is free to list.
     */
    public function duplicateFor(int $sellingOrgId, string $variantUuid): ?Offer;

    /**
     * This seller's offer for a variant INCLUDING a withdrawn one (ADR-076).
     *
     * **THE OPPOSITE QUESTION TO `duplicateFor()`, WHICH IS WHY IT IS A SECOND
     * METHOD.** That one asks "may this seller list here?" and deliberately cannot
     * see a withdrawn offer — a withdrawal is a soft delete and must not block a
     * fresh listing. This asks "has this seller ever listed here?", which the feed
     * needs for exactly one thing: telling a repeat withdrawal (idempotent, spec
     * §5) from a withdrawal of something never offered (`offer_not_found`).
     *
     * Widening `duplicateFor()` instead would have let a withdrawn offer block a
     * seller from ever re-listing the product.
     */
    public function anyForSellerAndVariant(int $sellingOrgId, string $variantUuid): ?Offer;

    /**
     * Every offer belonging to any of these organizations — the seller panel's
     * scope (ADR-030). An empty id list yields an empty collection, never
     * everyone's offers.
     *
     * @param array<int, int> $organizationIds
     *
     * @return Collection<int, Offer>
     */
    public function forOrganizations(array $organizationIds): Collection;

    /**
     * Live offers on a product, for the cascade and for counting. Ordered by
     * price so a caller that wants the cheapest does not re-sort.
     *
     * @return Collection<int, Offer>
     */
    public function forProduct(string $productUuid): Collection;

    /**
     * Offers a product-lifecycle cascade must act on (§3.5): everything still
     * active for that product.
     *
     * @return Collection<int, Offer>
     */
    public function activeForProduct(string $productUuid): Collection;

    /**
     * Every live offer attributed to one storefront — what a store going dark
     * or coming back has to re-evaluate in the search index (§10).
     *
     * Not filtered by status: a store's return must be able to re-index a
     * paused offer's absence as deliberately as an active offer's presence, and
     * that decision belongs to the caller.
     *
     * @return Collection<int, Offer>
     */
    public function forStore(string $storeUuid): Collection;

    /**
     * Offers paused BY a cascade — the exact set a re-publish reactivates,
     * leaving alone anything a seller paused for their own reasons.
     *
     * @return Collection<int, Offer>
     */
    public function cascadePausedForProduct(string $productUuid): Collection;
}
