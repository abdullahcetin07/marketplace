<?php

declare(strict_types=1);

namespace App\Modules\Offer;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Support\StorefrontRegistry;
use App\Modules\Offer\Application\Listeners\PauseOffersOnProductArchived;
use App\Modules\Offer\Application\Listeners\ResumeOffersOnProductPublished;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Offer\Infrastructure\Queries\OfferQuery;
use App\Modules\Offer\Infrastructure\Repositories\OfferRepository;
use App\Modules\Offer\Presentation\Policies\OfferPolicy;
use App\Modules\Offer\Presentation\Storefront\OfferStorefrontContributor;
use App\Shared\Enums\UserType;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(OfferRepositoryContract::class, OfferRepository::class);

        /*
        | The downstream read port (ADR-046). Offer is the single source of
        | truth for price and stock; Order, Search and the storefront ask
        | through this Core contract instead of importing Offer. It resolves
        | StoreQueryContract itself — the buy box's third eligibility condition
        | ("is the seller's store live") is Store's to answer.
        */
        $this->app->singleton(OfferQueryContract::class, OfferQuery::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('Modules/Offer/migrations'));

        Gate::policy(Offer::class, OfferPolicy::class);

        $this->subscribeToProductLifecycle();

        /*
        | The storefront product-listing contributor ADR-041 deferred, now
        | fulfilled (ADR-046). Registering a CLASS-STRING with the Core registry
        | is the whole of the coupling: Store composes this section without ever
        | naming Offer, and Offer never imports Store. The dependency points
        | from the contributor to the seam, exactly as ADR-036 designed it.
        */
        StorefrontRegistry::register(OfferStorefrontContributor::class);
    }

    /**
     * The product-lifecycle cascade (§3.5), subscribed BY CLASS-STRING.
     *
     * This is the one place the boundary is visible as a compromise rather than
     * a rule. Offer imports no module and Catalog is on the forbidden list with
     * no events escape hatch (`LayeringTest`), so it cannot name
     * `ProductArchived` as a class — it names it as a string and reads
     * `productUuid` off a plain object.
     *
     * WHY NOT GIVE CATALOG AN ESCAPE HATCH like Audit and Activity have? Because
     * those are consumers by nature — they subscribe to everything and never
     * act on a producer's data. Offer is a peer: relaxing the rule for one event
     * class would make the next Catalog import an argument rather than a build
     * failure, and this module's whole value is that it can be reasoned about
     * without reading Catalog.
     *
     * The cost is that a rename in Catalog breaks this at runtime, not at build
     * time. It is bounded by a feature test that fires the real events and
     * asserts the offers moved.
     */
    private function subscribeToProductLifecycle(): void
    {
        Event::listen(
            'App\Modules\Catalog\Domain\Events\ProductArchived',
            [PauseOffersOnProductArchived::class, 'handle'],
        );

        Event::listen(
            'App\Modules\Catalog\Domain\Events\ProductPublished',
            [ResumeOffersOnProductPublished::class, 'handle'],
        );
    }

    /**
     * Permissions are DERIVED from a resource registration, never hand-listed.
     *
     * ADMIN ONLY, and that is the substance of it: `offer.*` is a cross-org
     * platform power. A seller's authority over their own offers is an
     * ORGANIZATION CAPABILITY resolved through the Core contract (§9), not a
     * Spatie permission — Spatie `teams` is false, so a seller-guard
     * `offer.update` could not be scoped to one company and would grant it over
     * everyone's.
     */
    private function registerPermissions(): void
    {
        PermissionRegistry::resource('offer', [UserType::Admin]);

        // Reactive oversight (ADR-044) — the counterweight to shipping offers
        // unmoderated. Separated from the CRUD verbs because pulling a
        // merchant's listing is a different power from reading one.
        PermissionRegistry::ability('offer.suspend', [UserType::Admin]);
        PermissionRegistry::ability('offer.reinstate', [UserType::Admin]);
    }
}
