<?php

declare(strict_types=1);

namespace App\Modules\Offer;

use Illuminate\Support\ServiceProvider;

/**
 * Offer module wiring.
 *
 * What makes the shared catalog SELLABLE (ADR-042): an Offer is one seller
 * organization's price and stock for one catalog `ProductVariant`. One product,
 * many offers; the cheapest active in-stock offer is the buy box, computed at
 * read time and never stored (ADR-045).
 *
 * IT IMPORTS NO MODULE — the strictest boundary on the platform so far, and the
 * whole reason the Catalog was built shared (ADR-037/040). Offer reads:
 *
 *   - the catalog through the Core `CatalogQueryContract` (is this variant real,
 *     is its product published) and `CatalogBrowseContract` (the seller's
 *     "select a product to sell" search),
 *   - seller tenancy through `OrganizationAuthorizationContract`,
 *   - the store through `StoreQueryContract`,
 *
 * and exposes `OfferQueryContract` so Order, Inventory and Search never import
 * Offer either. Every cross-context reference is the ADR-040 pair: internal id
 * for tenancy filtering, uuid for identity.
 *
 * WHAT THIS MODULE IS NOT: cart, checkout, orders, payment, commission, tax, or
 * multi-warehouse inventory. It stores the price a seller sets; what the
 * platform takes and what the seller is paid are settled at Order/Payment time.
 * That boundary is what keeps an Offer a simple, seller-owned commercial record.
 *
 * @see docs/modules/Offer.md
 */
final class OfferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Offer/migrations'));
    }
}
